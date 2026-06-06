<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $this->call(RoleSeeder::class);

        $owner = User::create([
            'name' => 'Owner Cilupbah',
            'email' => 'cilupbah@ultra-fit.id',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
        ]);

        $owner->assignRole('owner');

        $this->call(\Modules\Region\Database\Seeders\RegionDatabaseSeeder::class);
        $this->call(\Modules\Channel\Database\Seeders\ChannelDatabaseSeeder::class);
    }
}
