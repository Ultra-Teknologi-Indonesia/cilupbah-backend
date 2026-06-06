<?php

namespace Modules\Warehouse\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Warehouse\Models\Location;

class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            'location_code' => 'LOC-' . $this->faker->unique()->randomNumber(5),
            'location_name' => $this->faker->company() . ' Warehouse',
            'location_type' => 'warehouse',
            'address' => $this->faker->address(),
            'province' => 'Jawa Barat',
            'city' => 'Bandung',
            'area' => 'Dago',
            'post_code' => '40135',
            'is_warehouse' => true,
            'is_multi_origin' => false,
            'is_active' => true,
        ];
    }
}
