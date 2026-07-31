<?php

namespace Modules\Sales\Tests\Unit;

use Modules\Sales\Support\BuyerCancelReasonHumanizer;
use PHPUnit\Framework\TestCase;

class BuyerCancelReasonHumanizerTest extends TestCase
{
    public function test_maps_tiktok_reason_keys_to_indonesian(): void
    {
        $this->assertSame('Ada harga lebih murah', BuyerCancelReasonHumanizer::humanize('ecom_order_to_ship_canceled_reason_better_price'));
        $this->assertSame('Tidak dibutuhkan lagi', BuyerCancelReasonHumanizer::humanize('ecom_order_unpaid_canceled_reason_no_longer_needed'));
        $this->assertSame('Salah varian (warna, ukuran, dll.)', BuyerCancelReasonHumanizer::humanize('buyer_cancel_wrong_item_variation_(colour,_size,_etc.)'));
    }

    public function test_leaves_human_text_untouched(): void
    {
        $this->assertSame('Berubah pikiran', BuyerCancelReasonHumanizer::humanize('Berubah pikiran'));
        $this->assertSame('No longer needed', BuyerCancelReasonHumanizer::humanize('No longer needed'));
    }

    public function test_prettifies_unknown_key_instead_of_showing_raw(): void
    {
        // Key tak dikenal: minimal dirapikan, bukan tampil mentah dengan prefix.
        $this->assertSame('Something new', BuyerCancelReasonHumanizer::humanize('ecom_order_to_ship_canceled_reason_something_new'));
    }

    public function test_empty_returns_null(): void
    {
        $this->assertNull(BuyerCancelReasonHumanizer::humanize(null));
        $this->assertNull(BuyerCancelReasonHumanizer::humanize('   '));
    }
}
