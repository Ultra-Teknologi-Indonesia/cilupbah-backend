<?php

namespace Modules\Sales\Tests\Unit;

use Modules\Sales\Support\OrderTotals;
use PHPUnit\Framework\TestCase;

class OrderTotalsTest extends TestCase
{
    public function test_full_component_formula(): void
    {
        // net = 1000 - 100 (disc) - 50 (other) + 70 (tax) = 920
        // additional = 30 (service) - 20 (voucher) + 15 (insurance) + 10 (proc) = 35
        // grand = 920 + max(0, 25 - 5) (shipping) + 35 = 975
        $grand = OrderTotals::grandTotal([
            'sub_total'            => 1000,
            'total_disc'           => 100,
            'other_discount'       => 50,
            'total_tax'            => 70,
            'shipping_cost'        => 25,
            'shipping_discount'    => 5,
            'insurance_cost'       => 15,
            'service_fee'          => 30,
            'seller_voucher'       => 20,
            'order_processing_fee' => 10,
            'price_includes_tax'   => false,
        ]);

        $this->assertSame(975.0, $grand);
    }

    public function test_price_includes_tax_excludes_tax(): void
    {
        $args = [
            'sub_total'          => 1000,
            'total_tax'          => 70,
            'price_includes_tax' => true,
        ];

        // tax not added when price already includes it
        $this->assertSame(1000.0, OrderTotals::grandTotal($args));
    }

    public function test_shipping_discount_never_makes_shipping_negative(): void
    {
        // shipping 10 - discount 40 clamps to 0, not -30
        $grand = OrderTotals::grandTotal([
            'sub_total'         => 100,
            'shipping_cost'     => 10,
            'shipping_discount' => 40,
        ]);

        $this->assertSame(100.0, $grand);
    }

    public function test_grand_total_never_negative(): void
    {
        $grand = OrderTotals::grandTotal([
            'sub_total'      => 100,
            'seller_voucher' => 500,
        ]);

        $this->assertSame(0.0, $grand);
    }

    public function test_missing_components_default_to_zero(): void
    {
        $this->assertSame(250.0, OrderTotals::grandTotal(['sub_total' => 250]));
    }
}
