<?php

namespace Modules\Sales\Tests\Unit;

use Modules\Sales\Enums\DisputeOutcome;
use Modules\Sales\Support\DisputeOutcomeNormalizer;
use PHPUnit\Framework\TestCase;

class DisputeOutcomeNormalizerTest extends TestCase
{
    public function test_null_returns_null(): void
    {
        $this->assertNull(DisputeOutcomeNormalizer::normalize('shopee', null));
        $this->assertNull(DisputeOutcomeNormalizer::normalize('shopee', ''));
    }

    public function test_canonical_fastpath(): void
    {
        $this->assertSame(
            DisputeOutcome::BUYER_WIN,
            DisputeOutcomeNormalizer::normalize('anything', 'BUYER_WIN'),
        );
    }

    public function test_shopee_mapping(): void
    {
        $this->assertSame(DisputeOutcome::PENDING, DisputeOutcomeNormalizer::normalize('shopee', 'JUDGING'));
        $this->assertSame(DisputeOutcome::BUYER_WIN, DisputeOutcomeNormalizer::normalize('shopee', 'ACCEPTED'));
        $this->assertSame(DisputeOutcome::SELLER_WIN, DisputeOutcomeNormalizer::normalize('shopee', 'REJECTED'));
        $this->assertSame(DisputeOutcome::SELLER_REFUSE_RETURN, DisputeOutcomeNormalizer::normalize('shopee', 'SELLER_REFUSE_RETURN'));
        $this->assertSame(DisputeOutcome::CANCELLED, DisputeOutcomeNormalizer::normalize('shopee', 'CLOSED'));
        $this->assertSame(DisputeOutcome::REFUNDED, DisputeOutcomeNormalizer::normalize('shopee', 'REFUNDED'));
    }

    public function test_tiktok_mapping(): void
    {
        $this->assertSame(DisputeOutcome::BUYER_WIN, DisputeOutcomeNormalizer::normalize('tiktok', 'APPROVED'));
        $this->assertSame(DisputeOutcome::SELLER_WIN, DisputeOutcomeNormalizer::normalize('tiktok', 'REJECT_BY_SELLER'));
        $this->assertSame(DisputeOutcome::CANCELLED, DisputeOutcomeNormalizer::normalize('tiktok', 'CANCELED'));
    }

    public function test_lazada_lowercase(): void
    {
        $this->assertSame(DisputeOutcome::BUYER_WIN, DisputeOutcomeNormalizer::normalize('lazada', 'approved'));
        $this->assertSame(DisputeOutcome::SELLER_WIN, DisputeOutcomeNormalizer::normalize('lazada', 'rejected'));
    }

    public function test_unknown_channel_falls_back_to_pending(): void
    {
        $this->assertSame(
            DisputeOutcome::PENDING,
            DisputeOutcomeNormalizer::normalize('bukalapak', 'WEIRD_STATE'),
        );
    }

    public function test_case_insensitive(): void
    {
        $this->assertSame(DisputeOutcome::BUYER_WIN, DisputeOutcomeNormalizer::normalize('shopee', 'accepted'));
    }
}
