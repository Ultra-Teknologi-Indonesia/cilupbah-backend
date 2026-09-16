<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class OriginalExceptionFailedJobProvider extends DatabaseUuidFailedJobProvider
{
    public function log($connection, $queue, $payload, $exception)
    {
        $uuid = parent::log($connection, $queue, $payload, $exception);

        if ($uuid === null) {
            return null;
        }

        try {
            $recorder = app(QueueFailureRecorder::class);
            $original = $recorder->originalFailure($uuid, $exception);

            DB::table('failed_jobs')
                ->where('uuid', $uuid)
                ->update([
                    'original_exception' => $recorder->originalFailureText($original),
                    'original_exception_class' => $original['class'],
                    'original_attempt' => $original['attempt'] ?? null,
                    'original_failed_at' => $original['occurred_at'] ?? now(),
                ]);
        } catch (Throwable $recordingException) {
            Log::critical('Failed job original exception could not be persisted.', [
                'job_uuid' => $uuid,
                'recording_exception' => $recordingException->getMessage(),
            ]);
        }

        return $uuid;
    }
}
