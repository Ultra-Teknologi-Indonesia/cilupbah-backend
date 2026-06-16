<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Modules\Channel\Services\LazadaProductMapper;
use Modules\Product\Models\Attribute;
use Modules\Product\Models\Category;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class LazadaVariantMappingTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;
    private Attribute $warna;
    private Attribute $ukuran;

    protected function setUp(): void
    {
        parent::setUp();

        $channel = Channel::create(['code' => 'lazada', 'name' => 'Lazada']);
        $this->category = Category::create(['name' => 'Baju']);
        $this->warna = Attribute::firstOrCreate(['name' => 'Warna'], ['type' => 'sales']);
        $this->ukuran = Attribute::firstOrCreate(['name' => 'Ukuran'], ['type' => 'sales']);

        $channelCategoryId = Uuid::uuid7()->toString();
        DB::table('channel_categories')->insert([
            'id' => $channelCategoryId, 'channel_id' => $channel->id, 'external_id' => 'CAT-100',
            'parent_external_id' => '0', 'name' => 'Baju LZ', 'is_leaf' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('category_channel_mappings')->insert([
            'category_id' => $this->category->id, 'channel_category_id' => $channelCategoryId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Warna = atribut tertutup 'color_family' (Merah → external RED-001).
        $colorAttrId = Uuid::uuid7()->toString();
        DB::table('channel_attributes')->insert([
            'id' => $colorAttrId, 'channel_category_id' => $channelCategoryId, 'external_id' => 'color_family',
            'name' => 'Color Family', 'is_required' => true, 'is_multiple' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('channel_attribute_options')->insert([
            'id' => Uuid::uuid7()->toString(), 'channel_attribute_id' => $colorAttrId,
            'external_id' => 'RED-001', 'name' => 'Merah', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('attribute_channel_mappings')->insert([
            'attribute_id' => $this->warna->id, 'channel_attribute_id' => $colorAttrId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Ukuran = atribut bebas 'size' (tanpa opsi → pass-through).
        $sizeAttrId = Uuid::uuid7()->toString();
        DB::table('channel_attributes')->insert([
            'id' => $sizeAttrId, 'channel_category_id' => $channelCategoryId, 'external_id' => 'size',
            'name' => 'Size', 'is_required' => false, 'is_multiple' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('attribute_channel_mappings')->insert([
            'attribute_id' => $this->ukuran->id, 'channel_attribute_id' => $sizeAttrId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function mapWith(array $options): array
    {
        $product = [
            'category_id' => $this->category->id,
            'name' => 'Kaos',
            'variants' => [['sku' => 'K-1', 'sell_price' => 50000, 'options' => $options]],
        ];

        $result = app(LazadaProductMapper::class)->map($product);

        return $result['Request']['Product']['Skus']['Sku'][0]['SaleProp'] ?? [];
    }

    public function test_closed_value_mapped_to_external_id_and_freetext_passthrough(): void
    {
        $saleProp = $this->mapWith([
            ['attribute_id' => $this->warna->id, 'value' => 'Merah'],
            ['attribute_id' => $this->ukuran->id, 'value' => 'L'],
        ]);

        // Warna 'Merah' → external_id 'RED-001'; Ukuran 'L' bebas → apa adanya.
        $this->assertSame('RED-001', $saleProp['color_family']);
        $this->assertSame('L', $saleProp['size']);
    }

    public function test_value_match_is_case_insensitive(): void
    {
        $saleProp = $this->mapWith([['attribute_id' => $this->warna->id, 'value' => 'merah']]);
        $this->assertSame('RED-001', $saleProp['color_family']);
    }

    public function test_unmapped_closed_value_falls_back_to_raw(): void
    {
        // 'Hijau' bukan opsi → fallback nilai mentah (ChannelListingValidator menangkapnya).
        $saleProp = $this->mapWith([['attribute_id' => $this->warna->id, 'value' => 'Hijau']]);
        $this->assertSame('Hijau', $saleProp['color_family']);
    }

    public function test_unmapped_attribute_is_skipped(): void
    {
        $bahan = Attribute::firstOrCreate(['name' => 'Bahan'], ['type' => 'sales']);
        $saleProp = $this->mapWith([['attribute_id' => $bahan->id, 'value' => 'Katun']]);
        $this->assertArrayNotHasKey('bahan', $saleProp);
        $this->assertEmpty($saleProp);
    }
}
