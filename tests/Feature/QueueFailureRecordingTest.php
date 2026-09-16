<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Channel\Support\WebhookFailureHandler;
use Modules\Sales\Jobs\AdminAlertJob;
use Tests\TestCase;

final class QueueFailureRecordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_attempt_and_failed_job_keep_the_original_exception(): void
    {
        $uuid = 'queue-failure-test-uuid';
        $rawPayload = json_encode([
            'uuid' => $uuid,
            'displayName' => 'Tests\\Feature\\ExampleJob',
            'data' => ['command' => 'serialized-secret-command'],
        ], JSON_THROW_ON_ERROR);

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('uuid')->andReturn($uuid);
        $job->shouldReceive('getJobId')->andReturn($uuid);
        $job->shouldReceive('getQueue')->andReturn('test-queue');
        $job->shouldReceive('getRawBody')->andReturn($rawPayload);
        $job->shouldReceive('resolveQueuedJobClass')->andReturn('Tests\\Feature\\ExampleJob');
        $job->shouldReceive('resolveName')->andReturn('Tests\\Feature\\ExampleJob@handle');
        $job->shouldReceive('attempts')->andReturn(1);

        $original = new \RuntimeException('Marketplace HTTP 429: retry-after=30');
        event(new JobExceptionOccurred('redis', $job, $original));

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'redis',
            'queue' => 'test-queue',
            'payload' => $rawPayload,
            'exception' => MaxAttemptsExceededException::forJob($job)->getMessage(),
            'failed_at' => now(),
        ]);

        event(new JobFailed('redis', $job, MaxAttemptsExceededException::forJob($job)));

        $this->assertDatabaseHas('queue_failure_attempts', [
            'job_uuid' => $uuid,
            'event_type' => 'attempt_exception',
            'exception_class' => \RuntimeException::class,
            'exception_message' => 'Marketplace HTTP 429: retry-after=30',
        ]);
        $this->assertDatabaseHas('queue_failure_attempts', [
            'job_uuid' => $uuid,
            'event_type' => 'job_failed',
        ]);
        $this->assertDatabaseHas('failed_jobs', [
            'uuid' => $uuid,
            'original_exception_class' => \RuntimeException::class,
            'original_attempt' => 1,
        ]);

        $failedJob = DB::table('failed_jobs')->where('uuid', $uuid)->first();
        $this->assertStringContainsString('Marketplace HTTP 429: retry-after=30', (string) $failedJob->original_exception);
    }

    public function test_failed_job_provider_enriches_the_failed_row_after_framework_persists_it(): void
    {
        $uuid = 'queue-failure-provider-test-uuid';
        $rawPayload = json_encode([
            'uuid' => $uuid,
            'displayName' => 'Tests\\Feature\\ExampleJob',
            'data' => ['command' => 'serialized-secret-command'],
        ], JSON_THROW_ON_ERROR);

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('uuid')->andReturn($uuid);
        $job->shouldReceive('getJobId')->andReturn($uuid);
        $job->shouldReceive('getQueue')->andReturn('test-queue');
        $job->shouldReceive('getRawBody')->andReturn($rawPayload);
        $job->shouldReceive('resolveQueuedJobClass')->andReturn('Tests\\Feature\\ExampleJob');
        $job->shouldReceive('resolveName')->andReturn('Tests\\Feature\\ExampleJob@handle');
        $job->shouldReceive('attempts')->andReturn(2);

        $original = new \RuntimeException('Lazada API Error: Api access frequency exceeds the limit');
        event(new JobExceptionOccurred('redis', $job, $original));
        $retryExhausted = MaxAttemptsExceededException::forJob($job);
        event(new JobFailed('redis', $job, $retryExhausted));

        app('queue.failer')->log(
            'redis',
            'test-queue',
            $rawPayload,
            $retryExhausted,
        );

        $failedJob = DB::table('failed_jobs')->where('uuid', $uuid)->first();

        $this->assertSame(\RuntimeException::class, $failedJob->original_exception_class);
        $this->assertSame(2, $failedJob->original_attempt);
        $this->assertStringContainsString('Api access frequency exceeds the limit', $failedJob->original_exception);
    }

    public function test_webhook_failure_uses_the_original_attempt_error(): void
    {
        Queue::fake();

        $uuid = 'webhook-failure-test-uuid';
        $eventKey = 'shopee:webhook:test-failure';
        ChannelWebhookInbox::create([
            'channel' => 'shopee',
            'shop_id' => 'shop-1',
            'event_key' => $eventKey,
            'event_type' => '3',
            'payload' => ['shop_id' => 'shop-1', 'code' => 3],
            'status' => 'RECEIVED',
            'received_at' => now(),
        ]);

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('uuid')->andReturn($uuid);
        $job->shouldReceive('getJobId')->andReturn($uuid);
        $job->shouldReceive('getQueue')->andReturn('shopee-orders');
        $job->shouldReceive('getRawBody')->andReturn(json_encode(['uuid' => $uuid], JSON_THROW_ON_ERROR));
        $job->shouldReceive('resolveQueuedJobClass')->andReturn('Modules\\Channel\\Jobs\\ProcessShopeeWebhook');
        $job->shouldReceive('resolveName')->andReturn('Modules\\Channel\\Jobs\\ProcessShopeeWebhook@handle');
        $job->shouldReceive('attempts')->andReturn(1);

        event(new JobExceptionOccurred(
            'redis',
            $job,
            new \RuntimeException('Shopee API asli: order service timeout'),
        ));

        WebhookFailureHandler::record(
            'shopee',
            $eventKey,
            ['shop_id' => 'shop-1'],
            MaxAttemptsExceededException::forJob($job),
            $uuid,
        );

        $this->assertSame(
            'Shopee API asli: order service timeout',
            (string) ChannelWebhookInbox::query()->where('event_key', $eventKey)->value('error'),
        );
        Queue::assertPushed(AdminAlertJob::class);
    }
}
