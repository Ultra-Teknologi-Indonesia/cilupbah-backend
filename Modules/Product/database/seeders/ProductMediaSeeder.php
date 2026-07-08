<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Product\Models\ProductMedia;
use Modules\Product\Models\ProductVariant;

class ProductMediaSeeder extends Seeder
{
    public function run(): void
    {
        $variants = ProductVariant::with('product')->get();

        $images = [
            'LAPTOP-001-8GB' => 'https://images.unsplash.com/photo-1588872657578-7efd1f1555ed?w=400&h=400&fit=crop',
            'MOUSE-001-BLK'  => 'https://images.unsplash.com/photo-1527864550417-7fd91fc51a46?w=400&h=400&fit=crop',
            'KBD-001-RED'    => 'https://images.unsplash.com/photo-1618384887929-16ec33fab9ef?w=400&h=400&fit=crop',
        ];

        foreach ($variants as $variant) {
            $url = $images[$variant->sku] ?? "https://placehold.co/400x400/e2e8f0/64748b?text={$variant->sku}";

            ProductMedia::updateOrCreate(
                [
                    'variant_id' => $variant->id,
                    'is_primary' => true,
                ],
                [
                    'product_id' => $variant->product_id,
                    'media_type' => 'image',
                    'url'        => $url,
                    'sort_order' => 0,
                ],
            );
        }

        $this->command->info('Product media seeded: ' . $variants->count() . ' images.');
    }
}
