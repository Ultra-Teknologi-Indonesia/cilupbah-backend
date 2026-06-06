<?php

namespace Modules\Warehouse\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Models\Location;

class LocationBinFactory extends Factory
{
    protected $model = LocationBin::class;

    public function definition(): array
    {
        $floor = 'L' . $this->faker->numberBetween(1, 5);
        $row = 'B' . $this->faker->numberBetween(1, 10);
        $col = 'K' . $this->faker->numberBetween(1, 10);
        $bin = 'R' . $this->faker->numberBetween(1, 50);

        return [
            'location_id' => Location::factory(),
            'floor_code' => $floor,
            'row_code' => $row,
            'column_code' => $col,
            'bin_code' => $bin,
            'bin_final_code' => "{$floor}-{$row}-{$col}-{$bin}",
            'max_qty' => $this->faker->numberBetween(0, 100),
            'is_inbound' => false,
        ];
    }
}
