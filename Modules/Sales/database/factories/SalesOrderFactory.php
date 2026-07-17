<?php

namespace Modules\Sales\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sales\Models\SalesOrder;

class SalesOrderFactory extends Factory
{
    protected $model = SalesOrder::class;

    public function definition(): array
    {
        $seq = $this->faker->unique()->numberBetween(100000, 999999);

        return [
            'salesorder_no'      => 'TEST-' . $seq,
            'channel_order_no'   => 'ORD-' . $seq,
            'customer_name'      => $this->faker->name(),
            'transaction_date'   => now(),
            'sub_total'          => 100000,
            'total_disc'         => 0,
            'total_tax'          => 0,
            'shipping_cost'      => 0,
            'insurance_cost'     => 0,
            'grand_total'        => 100000,
            'shipping_full_name' => $this->faker->name(),
            'shipping_phone'     => '+62' . $this->faker->numerify('81#########'),
            'shipping_address'   => $this->faker->address(),
            'shipping_city'      => 'Jakarta',
            'shipping_province'  => 'DKI Jakarta',
            'shipping_post_code' => '12345',
            'shipping_country'   => 'ID',
            'status'             => 'pending',
            'is_paid'            => false,
            'is_canceled'        => false,
            'source'             => 'manual',
        ];
    }
}
