<?php

namespace Modules\Channel\Tests\Unit;

use Modules\Channel\Services\LazadaToInternalOrderMapper;
use Modules\Channel\Services\ShopeeToInternalOrderMapper;
use Modules\Channel\Services\TikTokToInternalOrderMapper;
use Tests\TestCase;

/**
 * Konvensi kanonik lintas-channel untuk rincian pesanan:
 *   sub_total   = harga ASLI (gross, sebelum diskon produk)
 *   total_disc  = diskon produk (seller)
 *   sub_total - total_disc = harga bersih produk (net)
 *   grand_total = OTORITATIF dari channel (yang dibayar pembeli), bukan dihitung ulang
 *
 * Sejalan dgn Modules\Sales\Support\OrderTotals::grandTotal (net = sub_total - total_disc).
 */
class OrderSubtotalConventionTest extends TestCase
{
    public function test_shopee_sub_total_is_gross_and_disc_reconciles_to_net(): void
    {
        $order = [
            'order_sn'     => 'SP-CONV-1',
            'order_status' => 'completed',
            'total_amount' => 9000,   // buyer paid (mis. setelah subsidi platform) — OTORITATIF
            'estimated_shipping_fee' => 9700,
            'item_list'    => [
                [
                    'item_id' => 1, 'model_sku' => 'A', 'item_name' => 'A',
                    'model_original_price' => 50000, 'model_discounted_price' => 15000,
                    'model_quantity_purchased' => 1,
                ],
            ],
        ];

        $m = (new ShopeeToInternalOrderMapper())->map($order, 'shop-1');

        $this->assertSame(50000.0, (float) $m['sub_total'], 'sub_total harus gross (harga asli)');
        $this->assertSame(35000.0, (float) $m['total_disc'], 'total_disc harus diskon produk');
        $this->assertSame(15000.0, (float) $m['sub_total'] - (float) $m['total_disc'], 'sub_total - total_disc = net');
        $this->assertSame(9000.0, (float) $m['grand_total'], 'grand_total OTORITATIF dari channel');
    }

    public function test_shopee_grand_total_falls_back_to_net_plus_shipping_when_channel_total_absent(): void
    {
        $order = [
            'order_sn'     => 'SP-CONV-2',
            'order_status' => 'ready_to_ship',
            'estimated_shipping_fee' => 10000,
            // tak ada total_amount -> fallback
            'item_list'    => [
                ['item_id' => 1, 'model_sku' => 'A', 'model_original_price' => 50000, 'model_discounted_price' => 15000, 'model_quantity_purchased' => 2],
            ],
        ];

        $m = (new ShopeeToInternalOrderMapper())->map($order, 'shop-1');

        // net = 15000*2 = 30000 ; fallback = net + shipping (BUKAN gross), tak dobel diskon.
        $this->assertSame(100000.0, (float) $m['sub_total']);
        $this->assertSame(70000.0, (float) $m['total_disc']);
        $this->assertSame(40000.0, (float) $m['grand_total']);
    }

    public function test_tiktok_sub_total_is_gross_and_disc_reconciles_to_net(): void
    {
        $order = [
            'id'         => 'TK-CONV-1',
            'status'     => 'AWAITING_SHIPMENT',
            'payment'    => [
                'original_total_product_price' => 85000,
                'seller_discount'              => 60400,
                'total_amount'                 => 24600,
            ],
            'line_items' => [
                ['product_id' => 'p', 'seller_sku' => 'A', 'original_price' => 85000, 'seller_discount' => 60400, 'quantity' => 1],
            ],
        ];

        $m = (new TikTokToInternalOrderMapper())->map($order, 'shop-1');

        $this->assertSame(85000.0, (float) $m['sub_total']);
        $this->assertSame(60400.0, (float) $m['total_disc']);
        $this->assertSame(24600.0, (float) $m['sub_total'] - (float) $m['total_disc']);
        $this->assertSame(24600.0, (float) $m['grand_total']);
    }

    public function test_lazada_sub_total_is_gross_disc_is_product_discount_not_voucher(): void
    {
        $order = [
            'order_id'       => 'LZ-CONV-1',
            'statuses'       => ['pending'],
            'price'          => 45000,   // OTORITATIF
            'shipping_fee'   => 5450,
            'voucher'        => 5450,
            'voucher_seller' => 5450,
            'voucher_platform' => 0,
            'payment_method' => 'bank',
        ];
        $items = [
            ['sku' => 'A', 'item_price' => 50000, 'paid_price' => 39550, 'voucher_amount' => 5450, 'tax_amount' => 0],
        ];

        $m = (new LazadaToInternalOrderMapper())->map($order, $items, 'shop-1');

        $this->assertSame(50000.0, (float) $m['sub_total'], 'sub_total = gross (item_price)');
        $this->assertSame(10450.0, (float) $m['total_disc'], 'total_disc = diskon produk (item_price - paid_price), BUKAN voucher');
        $this->assertSame(39550.0, (float) $m['sub_total'] - (float) $m['total_disc'], 'net = paid_price');
        $this->assertSame(45000.0, (float) $m['grand_total'], 'grand_total OTORITATIF');
        $this->assertSame(5450.0, (float) $m['seller_voucher'], 'voucher tetap dilaporkan terpisah');
        $this->assertNotSame((float) $m['total_disc'], (float) $m['seller_voucher'], 'voucher tak lagi disamakan dgn total_disc');
    }

    public function test_lazada_no_product_discount_yields_zero_total_disc_even_with_voucher(): void
    {
        // Regresi bug lama: total_disc dulu = order voucher. Kini harus 0 saat tak ada diskon produk.
        $order = [
            'order_id'       => 'LZ-CONV-2',
            'statuses'       => ['pending'],
            'price'          => 45000,
            'shipping_fee'   => 5450,
            'voucher'        => 5450,
            'voucher_seller' => 5450,
            'payment_method' => 'bank',
        ];
        $items = [
            ['sku' => 'A', 'item_price' => 39550, 'paid_price' => 39550, 'voucher_amount' => 5450, 'tax_amount' => 0],
        ];

        $m = (new LazadaToInternalOrderMapper())->map($order, $items, 'shop-1');

        $this->assertSame(39550.0, (float) $m['sub_total']);
        $this->assertSame(0.0, (float) $m['total_disc']);
        $this->assertSame(5450.0, (float) $m['seller_voucher']);
    }
}
