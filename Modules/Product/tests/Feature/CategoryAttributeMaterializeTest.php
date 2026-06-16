<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Modules\Product\Models\Category;
use Modules\Product\Services\CategoryAttributeSyncService;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class CategoryAttributeMaterializeTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;
    private string $colorAttrId;
    private string $brandAttrId;

    protected function setUp(): void
    {
        parent::setUp();

        $channel = Channel::create(['code' => 'lazada', 'name' => 'Lazada']);
        $this->category = Category::create(['name' => 'Handphone']);

        $ccId = Uuid::uuid7()->toString();
        DB::table('channel_categories')->insert([
            'id' => $ccId, 'channel_id' => $channel->id, 'external_id' => 'CAT-100',
            'parent_external_id' => '0', 'name' => 'HP', 'is_leaf' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('category_channel_mappings')->insert([
            'category_id' => $this->category->id, 'channel_category_id' => $ccId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Sale-prop (jenis varian) dengan opsi.
        $this->colorAttrId = Uuid::uuid7()->toString();
        DB::table('channel_attributes')->insert([
            'id' => $this->colorAttrId, 'channel_category_id' => $ccId, 'external_id' => 'color_family',
            'name' => 'Color Family', 'is_required' => false, 'is_multiple' => false, 'is_sale_prop' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['Black', 'Red'] as $o) {
            DB::table('channel_attribute_options')->insert([
                'id' => Uuid::uuid7()->toString(), 'channel_attribute_id' => $this->colorAttrId,
                'external_id' => $o, 'name' => $o, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Spec wajib.
        $this->brandAttrId = Uuid::uuid7()->toString();
        DB::table('channel_attributes')->insert([
            'id' => $this->brandAttrId, 'channel_category_id' => $ccId, 'external_id' => 'brand',
            'name' => 'Brand', 'is_required' => true, 'is_multiple' => false, 'is_sale_prop' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Atribut sistem → harus dilewati.
        DB::table('channel_attributes')->insert([
            'id' => Uuid::uuid7()->toString(), 'channel_category_id' => $ccId, 'external_id' => 'price',
            'name' => 'Price', 'is_required' => true, 'is_multiple' => false, 'is_sale_prop' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function materialize(): int
    {
        return app(CategoryAttributeSyncService::class)->materializeFromChannels($this->category->id);
    }

    public function test_materializes_sales_and_spec_skips_system(): void
    {
        $added = $this->materialize();
        $this->assertSame(2, $added); // Color Family + Brand (Price dilewati)

        $this->assertDatabaseHas('attributes', ['name' => 'Color Family', 'type' => 'sales']);
        $this->assertDatabaseHas('attributes', ['name' => 'Brand', 'type' => 'spec']);
        $this->assertDatabaseMissing('attributes', ['name' => 'Price']);

        // category_attributes: Brand wajib, Color Family tidak.
        $brand = DB::table('attributes')->where('name', 'Brand')->value('id');
        $color = DB::table('attributes')->where('name', 'Color Family')->value('id');
        $this->assertTrue((bool) DB::table('category_attributes')->where('category_id', $this->category->id)->where('attribute_id', $brand)->value('is_required'));
        $this->assertFalse((bool) DB::table('category_attributes')->where('category_id', $this->category->id)->where('attribute_id', $color)->value('is_required'));

        // mapping + opsi internal.
        $this->assertEquals(2, DB::table('attribute_channel_mappings')->count());
        $this->assertEqualsCanonicalizing(['Black', 'Red'], DB::table('attribute_options')->where('attribute_id', $color)->pluck('value')->all());
    }

    public function test_is_idempotent(): void
    {
        $this->assertSame(2, $this->materialize());
        $this->assertSame(0, $this->materialize()); // run kedua tak menambah

        $this->assertEquals(2, DB::table('category_attributes')->where('category_id', $this->category->id)->count());
        $this->assertEquals(2, DB::table('attributes')->whereIn('name', ['Color Family', 'Brand'])->count());
    }

    public function test_respects_existing_curated_mapping(): void
    {
        // Merchant sudah punya "Warna" (sales) dipetakan ke color_family.
        $warnaId = DB::table('attributes')->insertGetId(['name' => 'Warna', 'type' => 'sales', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('attribute_channel_mappings')->insert([
            'attribute_id' => $warnaId, 'channel_attribute_id' => $this->colorAttrId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->materialize();

        // Reuse "Warna", TIDAK buat "Color Family".
        $this->assertDatabaseMissing('attributes', ['name' => 'Color Family']);
        $this->assertDatabaseHas('category_attributes', ['category_id' => $this->category->id, 'attribute_id' => $warnaId]);
    }
}
