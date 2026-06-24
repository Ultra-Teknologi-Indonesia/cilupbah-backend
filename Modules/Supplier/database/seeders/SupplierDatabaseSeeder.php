<?php

namespace Modules\Supplier\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Channel\Models\ChannelShop;
use Modules\Supplier\Models\Contact;
use Modules\Supplier\Models\ContactCategory;

class SupplierDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Pelanggan Umum', 'description' => 'Kategori pelanggan umum'],
            ['name' => 'Reseller', 'description' => 'Kategori reseller'],
        ];

        foreach ($categories as $cat) {
            ContactCategory::firstOrCreate(
                ['name' => $cat['name']],
                ['description' => $cat['description']]
            );
        }

        $this->seedMarketplaceContacts();
    }

    private function seedMarketplaceContacts(): void
    {
        $category = ContactCategory::where('name', 'Pelanggan Umum')->first();

        $shops = ChannelShop::with('channel')
            ->whereNull('disconnected_at')
            ->get();

        foreach ($shops as $shop) {
            $channelName = $shop->channel?->name ?? 'Marketplace';
            $code = 'MP-' . Str::upper(Str::substr($shop->channel?->code ?? 'mp', 0, 4)) . '-' . Str::upper(Str::substr($shop->shop_id, 0, 8));

            Contact::firstOrCreate(
                ['code' => $code],
                [
                    'name'        => $channelName . ' - ' . $shop->shop_name,
                    'type'        => Contact::TYPE_BOTH,
                    'category_id' => $category?->id,
                    'is_system'   => true,
                    'status'      => Contact::STATUS_ACTIVE,
                ]
            );
        }
    }
}
