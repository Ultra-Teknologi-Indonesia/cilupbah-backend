<?php

namespace Modules\Realtime\Tests\Unit;

use Illuminate\Support\Facades\Redis;
use Modules\Realtime\Services\RealtimeEventPublisher;
use Tests\TestCase;

class RealtimeEventPublisherTest extends TestCase
{
    public function test_publish_writes_a_bounded_user_stream_event(): void
    {
        config([
            'realtime.enabled' => true,
            'realtime.redis_connection' => 'default',
            'realtime.stream_max_length' => 1000,
        ]);

        $redis = \Mockery::mock();
        $redis->shouldReceive('xadd')
            ->once()
            ->withArgs(function (
                string $key,
                string $id,
                array $fields,
                int $maxLength,
                bool $approximate,
            ): bool {
                return $key === 'realtime:user:user-1'
                    && $id === '*'
                    && $fields['topic'] === 'export:job-1'
                    && $fields['event_type'] === 'export.progress'
                    && json_decode($fields['payload'], true) === ['status' => 'ready']
                    && $maxLength === 1000
                    && $approximate === true;
            })
            ->andReturn('1-0');

        Redis::shouldReceive('connection')
            ->once()
            ->with('default')
            ->andReturn($redis);

        app(RealtimeEventPublisher::class)->publish(
            'user-1',
            'export:job-1',
            'export.progress',
            ['status' => 'ready'],
        );

        $this->assertSame(
            'realtime:user:user-1',
            app(RealtimeEventPublisher::class)->streamKey('user-1'),
        );
    }
}
