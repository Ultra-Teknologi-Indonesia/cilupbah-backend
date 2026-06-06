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
            $existing = Channel::where('code', $channel['code'])->first();
            if (!$existing) {
                Channel::create([
                    'id' => \Ramsey\Uuid\Uuid::uuid7()->getHex()->toString(),
                    'code' => $channel['code'],
                    'name' => $channel['name'],
                    'is_active' => true
                ]);
            } else {
                $existing->update(['name' => $channel['name'], 'is_active' => true]);
            }
        }
    }
}
