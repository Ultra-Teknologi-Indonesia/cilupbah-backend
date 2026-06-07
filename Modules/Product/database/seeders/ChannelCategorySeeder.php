<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Ramsey\Uuid\Uuid;

class ChannelCategorySeeder extends Seeder
{
    public function run(): void
    {
        $tiktokChannel = Channel::where('code', 'tiktok')->first();
        
        if (!$tiktokChannel) {
            $this->command->warn('TikTok channel not found. Skipping ChannelCategorySeeder.');
            return;
        }

        $jsonFile = base_path('tiktok_categories.json');
        
        if (!file_exists($jsonFile)) {
            $this->command->warn('tiktok_categories.json file not found. Skipping ChannelCategorySeeder.');
            return;
        }

        $data = json_decode(file_get_contents($jsonFile), true);
        
        if (!isset($data['data']['categories'])) {
            $this->command->warn('Invalid JSON structure in tiktok_categories.json. Skipping.');
            return;
        }

        $categories = $data['data']['categories'];
        
        // Chunk inserts to avoid memory exhaustion (15k rows)
        $chunks = array_chunk($categories, 500);

        $this->command->info('Importing ' . count($categories) . ' TikTok categories...');

        foreach ($chunks as $chunk) {
            $insertData = [];
            foreach ($chunk as $item) {
                $insertData[] = [
                    'id' => Uuid::uuid7()->toString(),
                    'channel_id' => $tiktokChannel->id,
                    'external_id' => $item['id'],
                    'parent_external_id' => $item['parent_id'],
                    'name' => $item['local_name'],
                    'is_leaf' => $item['is_leaf'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            
            DB::table('channel_categories')->insertOrIgnore($insertData);
        }
        
        $this->command->info('Finished importing TikTok categories.');
    }
}
