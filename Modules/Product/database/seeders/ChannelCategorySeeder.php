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

        if (! $tiktokChannel) {
            $this->command->warn('TikTok channel not found. Skipping ChannelCategorySeeder.');
            return;
        }

        $data = json_decode(
            file_get_contents(module_path('Product', 'database/data/tiktok_categories_l3.json')),
            true
        );

        $categories = $data['categories'];

        $chunks = array_chunk($categories, 500);

        $this->command->info('Importing ' . count($categories) . ' TikTok categories...');

        foreach ($chunks as $chunk) {
            $insertData = [];
            foreach ($chunk as $item) {
                $exists = DB::table('channel_categories')
                    ->where('channel_id', $tiktokChannel->id)
                    ->where('external_id', $item['id'])
                    ->exists();

                if ($exists) {
                    continue;
                }

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

            if (! empty($insertData)) {
                DB::table('channel_categories')->insert($insertData);
            }
        }

        $this->command->info('Finished importing TikTok categories.');
    }
}
