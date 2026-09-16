<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\QueueFailureAttempt;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class QueueFailureRecorder
{
    private const EVENT_ATTEMPT = 'attempt_exception';

    private const EVENT_FAILED = 'job_failed';

    private const MAX_MESSAGE_LENGTH = 16000;

    private const MAX_TRACE_LENGTH = 100000;

    private const REDACTED = '[REDACTED]';

    private const SENSITIVE_KEYS = [
        'access_token',
        'authorization',
        'client_secret',
        'consumer_key',
        'consumer_secret',
        'cookie',
        'password',
        'refresh_token',
        'secret',
        'signature',
        'token',
        'webhook_secret',
    ];

    public function recordException(JobExceptionOccurred $event): void
    {
        $this->record(
            $event->connectionName,
            $event->job,
            $event->exception,
            self::EVENT_ATTEMPT,
        );
    }

    public function recordFailed(JobFailed $event): void
    {
        $this->record(
            $event->connectionName,
            $event->job,
            $event->exception,
            self::EVENT_FAILED,
        );

        $this->synchronizeFailedJob($event);
    }

    public function originalFailure(string $jobUuid, Throwable $fallback): array
    {
        $failure = QueueFailureAttempt::query()
            ->where('job_uuid', $jobUuid)
            ->where('event_type', self::EVENT_ATTEMPT)
            ->latest('attempt')
            ->latest('id')
            ->first();

        if (! $failure) {
            return $this->exceptionData($fallback);
        }

        return [
            'class' => (string) $failure->exception_class,
            'message' => (string) $failure->exception_message,
            'code' => $failure->exception_code,
            'file' => $failure->exception_file,
            'line' => $failure->exception_line,
            'trace' => $failure->exception_trace,
            'chain' => $failure->exception_chain,
            'attempt' => $failure->attempt,
            'occurred_at' => $failure->occurred_at,
        ];
    }

    public function messageForJob(?string $jobUuid, Throwable $fallback): string
    {
        if (! $this->isRetryExhaustedWrapper($fallback)
            || $jobUuid === null
            || $jobUuid === '') {
            return $this->exceptionData($fallback)['message'];
        }

        return $this->originalFailure($jobUuid, $fallback)['message'];
    }

    public function originalFailureText(array $failure): string
    {
        return $this->formatException($failure);
    }

    public function hasAttemptException(?string $jobUuid): bool
    {
        return $jobUuid !== null
            && $jobUuid !== ''
            && QueueFailureAttempt::query()
                ->where('job_uuid', $jobUuid)
                ->where('event_type', self::EVENT_ATTEMPT)
                ->exists();
    }

    private function record(
        string $connectionName,
        object $job,
        Throwable $exception,
        string $eventType,
    ): void {
        try {
            $jobUuid = $this->jobUuid($job);
            $attempt = max(1, (int) $job->attempts());
            $data = $this->exceptionData($exception);
            $payloadMetadata = $this->payloadMetadata($job);

            QueueFailureAttempt::query()->insertOrIgnore([
                'job_uuid' => $jobUuid,
                'connection' => $this->limit($connectionName, 191),
                'queue' => $this->limit((string) $job->getQueue(), 191),
                'job_class' => $this->jobClass($job),
                'exception_class' => $data['class'],
                'exception_message' => $data['message'],
                'exception_code' => $data['code'],
                'exception_file' => $data['file'],
                'exception_line' => $data['line'],
                'exception_trace' => $data['trace'],
                'exception_chain' => json_encode($data['chain'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'job_payload' => $payloadMetadata === null
                    ? null
                    : json_encode($payloadMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'attempt' => $attempt,
                'event_type' => $eventType,
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $recordingException) {
            Log::critical('Queue failure audit could not be persisted.', [
                'recording_exception' => $recordingException->getMessage(),
                'original_exception' => $exception::class.': '.$exception->getMessage(),
            ]);
        }
    }

    private function synchronizeFailedJob(JobFailed $event): void
    {
        try {
            $jobUuid = $this->jobUuid($event->job);
            if ($jobUuid === null) {
                return;
            }

            $original = $this->originalFailureForFailedEvent($event, $jobUuid);
            $originalException = $this->formatException($original);

            DB::table('failed_jobs')
                ->where('uuid', $jobUuid)
                ->update([
                    'original_exception' => $originalException,
                    'original_exception_class' => $original['class'],
                    'original_attempt' => $original['attempt'] ?? null,
                    'original_failed_at' => $original['occurred_at'] ?? now(),
                ]);
        } catch (Throwable $exception) {
            Log::critical('Failed job original exception could not be linked.', [
                'recording_exception' => $exception->getMessage(),
            ]);
        }
    }

    private function originalFailureForFailedEvent(JobFailed $event, string $jobUuid): array
    {
        if (! $this->isRetryExhaustedWrapper($event->exception)) {
            $data = $this->exceptionData($event->exception);
            $data['attempt'] = max(1, (int) $event->job->attempts());
            $data['occurred_at'] = now();

            return $data;
        }

        return $this->originalFailure($jobUuid, $event->exception);
    }

    private function isRetryExhaustedWrapper(Throwable $exception): bool
    {
        if ($exception instanceof MaxAttemptsExceededException) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'maxattemptsexceeded')
            || str_contains($message, 'attempted too many times');
    }

    private function jobUuid(object $job): ?string
    {
        try {
            $uuid = $job->uuid();
        } catch (Throwable) {
            $uuid = null;
        }

        if ($uuid !== null && $uuid !== '') {
            return (string) $uuid;
        }

        try {
            $id = $job->getJobId();
        } catch (Throwable) {
            $id = null;
        }

        return $id !== null && $id !== '' ? (string) $id : null;
    }

    private function jobClass(object $job): ?string
    {
        try {
            return $this->limit((string) $job->resolveQueuedJobClass(), 1000);
        } catch (Throwable) {
            return null;
        }
    }

    private function payloadMetadata(object $job): ?array
    {
        try {
            $payload = json_decode($job->getRawBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        unset($payload['data']);

        return $this->sanitize($payload);
    }

    private function exceptionData(Throwable $exception): array
    {
        $chain = [];
        $current = $exception;
        $depth = 0;
        $message = $this->exceptionMessage($exception);

        while ($current !== null && $depth < 10) {
            $chain[] = [
                'class' => $current::class,
                'message' => $this->redact($current->getMessage()),
                'code' => (string) $current->getCode(),
                'file' => $this->limit($current->getFile(), 2000),
                'line' => $current->getLine(),
            ];
            $current = $current->getPrevious();
            $depth++;
        }

        return [
            'class' => $exception::class,
            'message' => $message,
            'code' => (string) $exception->getCode(),
            'file' => $this->limit($exception->getFile(), 2000),
            'line' => $exception->getLine(),
            'trace' => $this->redact($this->limit($exception->getTraceAsString(), self::MAX_TRACE_LENGTH)),
            'chain' => $chain,
            'attempt' => null,
            'occurred_at' => null,
        ];
    }

    private function exceptionMessage(Throwable $exception): string
    {
        $message = $this->redact($exception->getMessage());
        $rawMessage = property_exists($exception, 'rawMessage')
            ? $exception->rawMessage
            : null;

        if (! is_string($rawMessage) || trim($rawMessage) === '' || str_contains($message, $rawMessage)) {
            return $message;
        }

        return $this->limit(
            $message.' | raw='.$this->redact($rawMessage),
            self::MAX_MESSAGE_LENGTH,
        );
    }

    private function formatException(array $exception): string
    {
        $location = $exception['file'] !== null
            ? " in {$exception['file']}:{$exception['line']}"
            : '';

        return $exception['class'].': '.$exception['message'].$location
            .($exception['trace'] !== null ? "\n".$exception['trace'] : '');
    }

    private function sanitize(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            return self::REDACTED;
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $childKey => $childValue) {
                $result[(string) $childKey] = $this->sanitize($childValue, (string) $childKey);
            }

            return $result;
        }

        return is_string($value) ? $this->limit($this->redact($value), 4000) : $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));

        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if ($normalized === $sensitiveKey || str_contains($normalized, $sensitiveKey)) {
                return true;
            }
        }

        return $normalized === 'command';
    }

    private function redact(string $value): string
    {
        $patterns = [
            '/((?:access[_-]?token|refresh[_-]?token|client[_-]?secret|consumer[_-]?(?:key|secret)|authorization|webhook[_-]?secret|password|signature)\\s*[:=]\\s*)([^\\s,;}]+)/i',
            '/(Bearer\\s+)[A-Za-z0-9._~+\\/-]+=*/i',
        ];

        return $this->limit(preg_replace($patterns, '$1'.self::REDACTED, $value) ?? $value, self::MAX_MESSAGE_LENGTH);
    }

    private function limit(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }
}
