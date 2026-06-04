<?php

namespace Modules\Channel\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;

class ChannelDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $channels = [
            ['code' => 'shopee', 'name' => 'Shopee'],
            ['code' => 'tiktok', 'name' => 'TikTok Shop'],
            ['code' => 'lazada', 'name' => 'Lazada'],
            ['code' => 'blibli', 'name' => 'Blibli'],
        ];

        foreach ($channels as $channel) {
            Channel::updateOrCreate(
                ['code' => $channel['code']],
                ['name' => $channel['name'], 'is_active' => true]
            );
        }
    }
}
