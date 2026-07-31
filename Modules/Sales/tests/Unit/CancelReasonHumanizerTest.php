<?php

namespace Modules\Sales\Tests\Unit;

use Modules\Sales\Support\CancelReasonHumanizer;
use PHPUnit\Framework\TestCase;

class CancelReasonHumanizerTest extends TestCase
{
    public function test_buyer_keys_map_to_indonesian(): void
    {
        $this->assertSame('Ada harga lebih murah', CancelReasonHumanizer::buyer('ecom_order_to_ship_canceled_reason_better_price'));
        $this->assertSame('Tidak dibutuhkan lagi', CancelReasonHumanizer::buyer('ecom_order_unpaid_canceled_reason_no_longer_needed'));
        $this->assertSame('Perlu memasukkan/mengubah kode kupon', CancelReasonHumanizer::buyer('buyer_cancel_need_to_input/change_coupon_code'));
    }

    public function test_seller_reject_keys_map_to_indonesian(): void
    {
        $this->assertSame('Produk sudah dikemas', CancelReasonHumanizer::sellerReject('seller_reject_apply_product_has_been_packed'));
        $this->assertSame('Pengiriman sesuai jadwal', CancelReasonHumanizer::sellerReject('order_manage_list_action_respond_popup_reject_reason_delivered'));
        $this->assertSame('Sudah sepakat dengan pembeli', CancelReasonHumanizer::sellerReject('order_manage_list_action_respond_popup_reject_reason_buyer_agree'));
    }

    public function test_human_text_untouched_both_methods(): void
    {
        $this->assertSame('Berubah pikiran', CancelReasonHumanizer::buyer('Berubah pikiran'));
        $this->assertSame('Stok sudah disiapkan', CancelReasonHumanizer::sellerReject('Stok sudah disiapkan'));
    }

    public function test_unknown_key_prettified_not_raw(): void
    {
        $this->assertSame('Something new', CancelReasonHumanizer::buyer('ecom_order_to_ship_canceled_reason_something_new'));
        $this->assertSame('Mystery reason', CancelReasonHumanizer::sellerReject('seller_reject_apply_mystery_reason'));
    }

    public function test_empty_returns_null(): void
    {
        $this->assertNull(CancelReasonHumanizer::buyer(null));
        $this->assertNull(CancelReasonHumanizer::sellerReject('   '));
    }
}
