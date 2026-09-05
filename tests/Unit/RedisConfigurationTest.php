<?php

namespace Tests\Unit;

use Tests\TestCase;

class RedisConfigurationTest extends TestCase
{
    public function test_redis_ports_are_resolved_as_integers(): void
    {
        $this->assertIsInt(config('database.redis.default.port'));
        $this->assertIsInt(config('database.redis.cache.port'));
    }
}
