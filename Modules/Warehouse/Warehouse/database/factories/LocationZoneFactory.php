<?php

namespace Modules\Warehouse\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Warehouse\Models\LocationZone;
use Modules\Warehouse\Models\Location;

class LocationZoneFactory extends Factory
{
    protected $model = LocationZone::class;

    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'name' => 'Zone ' . $this->faker->unique()->word(),
        ];
    }
}
