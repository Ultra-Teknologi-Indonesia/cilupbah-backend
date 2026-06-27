<?php

namespace Modules\Supplier\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Supplier\Models\Contact;
use Modules\Supplier\Models\ContactCategory;

class SupplierDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['code' => 'PLG-UMUM', 'name' => 'Pelanggan Umum', 'description' => 'Kategori pelanggan umum'],
            ['code' => 'RESELLER', 'name' => 'Reseller', 'description' => 'Kategori reseller'],
        ];

        foreach ($categories as $cat) {
            ContactCategory::updateOrCreate(
                ['name' => $cat['name']],
                ['code' => $cat['code'], 'description' => $cat['description']]
            );
        }

        $this->seedMarketplaceContacts();
    }

    private function seedMarketplaceContacts(): void
    {
        $category = ContactCategory::where('code', 'PLG-UMUM')->first();

        Contact::where('is_system', true)
            ->where('code', 'like', 'MP-%')
            ->delete();

        $channels = Channel::whereHas('shops', function ($q) {
            $q->whereNull('disconnected_at');
        })->get();

        foreach ($channels as $channel) {
            Contact::create([
                'code'        => 'MP-' . Str::upper($channel->code),
                'name'        => $channel->name,
                'type'        => Contact::TYPE_BOTH,
                'category_id' => $category?->id,
                'is_system'   => true,
                'is_company'  => true,
                'status'      => Contact::STATUS_ACTIVE,
            ]);
        }
    }
}
