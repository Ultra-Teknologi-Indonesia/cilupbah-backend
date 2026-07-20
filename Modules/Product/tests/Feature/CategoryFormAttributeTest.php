<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Modules\Product\Models\Category;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class CategoryFormAttributeTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createPrivilegedUser();
        $this->actingAs($this->user);

        $this->category = Category::create(['name' => 'Handphone']);

        $brandId = DB::table('attributes')->insertGetId(['name' => 'Brand', 'type' => 'spec', 'created_at' => now(), 'updated_at' => now()]);
        $warnaId = DB::table('attributes')->insertGetId(['name' => 'Warna', 'type' => 'sales', 'created_at' => now(), 'updated_at' => now()]);

        foreach (['Merah', 'Biru'] as $val) {
            DB::table('attribute_options')->insert(['attribute_id' => $warnaId, 'value' => $val, 'created_at' => now(), 'updated_at' => now()]);
        }

        DB::table('category_attributes')->insert([
            ['category_id' => $this->category->id, 'attribute_id' => $brandId, 'is_required' => true, 'created_at' => now(), 'updated_at' => now()],
            ['category_id' => $this->category->id, 'attribute_id' => $warnaId, 'is_required' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $channel = Channel::create(['code' => 'lazada', 'name' => 'Lazada']);
        $ccId = Uuid::uuid7()->toString();
        DB::table('channel_categories')->insert([
            'id' => $ccId, 'channel_id' => $channel->id, 'external_id' => 'CAT-100',
            'parent_external_id' => '0', 'name' => 'HP', 'is_leaf' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $chBrand = Uuid::uuid7()->toString();
        DB::table('channel_attributes')->insert([
            'id' => $chBrand, 'channel_category_id' => $ccId, 'external_id' => 'brand', 'name' => 'Brand',
            'is_required' => true, 'is_multiple' => false, 'is_sale_prop' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('attribute_channel_mappings')->insert([
            'attribute_id' => $brandId, 'channel_attribute_id' => $chBrand, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_returns_specifications_and_variant_types_with_channel_status(): void
    {
        $res = $this->getJson("/api/v1/categories/{$this->category->id}/form-attributes");

        $res->assertOk()
            ->assertJsonCount(1, 'data.specifications')
            ->assertJsonCount(1, 'data.variant_types')
            ->assertJsonPath('data.specifications.0.name', 'Brand')
            ->assertJsonPath('data.specifications.0.is_required', true)
            ->assertJsonPath('data.specifications.0.channels.lazada.mapped', true)
            ->assertJsonPath('data.specifications.0.channels.lazada.required', true)
            ->assertJsonPath('data.variant_types.0.name', 'Warna')
            ->assertJsonCount(2, 'data.variant_types.0.options');
    }

    public function test_non_leaf_category_returns_422(): void
    {

        $parent = Category::create(['name' => 'Elektronik']);
        Category::create(['name' => 'Sub', 'parent_id' => $parent->id]);

        $this->getJson("/api/v1/categories/{$parent->id}/form-attributes")->assertStatus(422);
    }

    public function test_unknown_category_returns_404(): void
    {
        $this->getJson('/api/v1/categories/999999/form-attributes')->assertStatus(404);
    }
}
