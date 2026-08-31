<?php

namespace Modules\Outbound\Tests\Unit;

use Modules\Outbound\Support\ChannelInstantSignal;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChannelInstantSignalTest extends TestCase
{
    #[DataProvider('instantTypeProvider')]
    public function test_returns_true_for_channel_instant_categories(string $type): void
    {
        $this->assertTrue(ChannelInstantSignal::fromTypes($type));
    }

    public static function instantTypeProvider(): array
    {
        return [
            ['INSTANT'],
            ['Same Day'],
            ['same_day'],
            ['Instant Hemat'],
        ];
    }

    #[DataProvider('nonInstantTypeProvider')]
    public function test_returns_false_for_explicit_non_instant_categories(string $type): void
    {
        $this->assertFalse(ChannelInstantSignal::fromTypes($type));
    }

    public static function nonInstantTypeProvider(): array
    {
        return [
            ['REGULAR'],
            ['STANDARD'],
            ['Economical'],
            ['Next-day delivery'],
            ['EXPRESS'],
        ];
    }

    public function test_returns_null_for_generic_channel_values(): void
    {
        $this->assertNull(ChannelInstantSignal::fromTypes('TIKTOK', 'FULFILLMENT_BY_SELLER'));
    }
}
