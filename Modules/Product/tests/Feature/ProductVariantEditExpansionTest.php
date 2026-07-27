<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Attribute;
use Modules\Product\Models\Category;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class ProductVariantEditExpansionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Category $category;
    protected Attribute $warna;
    protected Attribute $ukuran;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createPrivilegedUser();
        $this->actingAs($this->user);

        $this->category = Category::create(['name' => 'Handphone']);
        $this->warna = Attribute::firstOrCreate(['name' => 'Warna'], ['type' => 'sales']);
        $this->ukuran = Attribute::firstOrCreate(['name' => 'Ukuran'], ['type' => 'sales']);
    }

    private function createIp17(int $price = 7000): string
    {
        $res = $this->postJson('/api/v1/products', [
            'name' => 'iPhone 17',
            'sku' => 'IP17',
            'category_id' => $this->category->id,
            'media' => [['url' => 'https://img.test/a.jpg', 'media_type' => 'image']],
            'variation_types' => [['attribute_id' => $this->warna->id, 'sort_order' => 0]],
            'variants' => [
                ['sku' => 'IP17-BLUE', 'sell_price' => $price, 'is_active' => true,
                    'options' => [['attribute_id' => $this->warna->id, 'value' => 'Blue']]],
                ['sku' => 'IP17-RED', 'sell_price' => $price, 'is_active' => true,
                    'options' => [['attribute_id' => $this->warna->id, 'value' => 'Red']]],
            ],
        ]);
        $res->assertCreated();

        return $res->json('data.product_id');
    }

    private function expandPayload(): array
    {
        $w = $this->warna->id;
        $u = $this->ukuran->id;

        return [
            'variation_types' => [
                ['attribute_id' => $w, 'sort_order' => 0],
                ['attribute_id' => $u, 'sort_order' => 1],
            ],
            'variants' => [
                ['sku' => 'IP17-BLUE-256', 'sell_price' => 20000, 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Blue'], ['attribute_id' => $u, 'value' => '256/8']]],
                ['sku' => 'IP17-BLUE-512', 'sell_price' => 25000, 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Blue'], ['attribute_id' => $u, 'value' => '512/16']]],
                ['sku' => 'IP17-RED-256', 'sell_price' => 20000, 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Red'], ['attribute_id' => $u, 'value' => '256/8']]],
                ['sku' => 'IP17-RED-512', 'sell_price' => 25000, 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Red'], ['attribute_id' => $u, 'value' => '512/16']]],
            ],
        ];
    }

    public function test_expand_one_type_to_two_creates_cartesian_and_supersedes_old(): void
    {
        $id = $this->createIp17();

        $this->putJson("/api/v1/products/{$id}", $this->expandPayload())->assertOk();

        $this->assertEquals(4, DB::table('product_variants')->where('product_id', $id)->where('is_active', true)->count());
        foreach (['IP17-BLUE-256', 'IP17-BLUE-512', 'IP17-RED-256', 'IP17-RED-512'] as $sku) {
            $this->assertDatabaseHas('product_variants', ['product_id' => $id, 'sku' => $sku, 'is_active' => true]);
        }

        foreach (['IP17-BLUE', 'IP17-RED'] as $sku) {
            $old = DB::table('product_variants')->where('product_id', $id)->where('sku', $sku)->first();
            $this->assertNotNull($old, "SKU lama {$sku} tidak boleh dihapus");
            $this->assertFalse((bool) $old->is_active);
            $this->assertNotNull($old->superseded_at);
        }

        $this->assertEquals(6, DB::table('product_variants')->where('product_id', $id)->count());
        $this->assertEquals(2, DB::table('product_variation_types')->where('product_id', $id)->count());
    }

    public function test_removing_existing_type_is_rejected_422(): void
    {
        $id = $this->createIp17();

        $this->putJson("/api/v1/products/{$id}", [
            'variation_types' => [['attribute_id' => $this->ukuran->id, 'sort_order' => 0]],
            'variants' => [
                ['sku' => 'IP17-256', 'sell_price' => 1000, 'is_active' => true,
                    'options' => [['attribute_id' => $this->ukuran->id, 'value' => '256/8']]],
            ],
        ])->assertStatus(422);
    }

    public function test_removing_option_value_without_stock_supersedes(): void
    {
        $id = $this->createIp17();
        $w = $this->warna->id;

        $this->putJson("/api/v1/products/{$id}", [
            'variation_types' => [['attribute_id' => $w, 'sort_order' => 0]],
            'variants' => [
                ['sku' => 'IP17-BLUE', 'sell_price' => 7000, 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Blue']]],
            ],
        ])->assertOk();

        $red = DB::table('product_variants')->where('product_id', $id)->where('sku', 'IP17-RED')->first();
        $this->assertFalse((bool) $red->is_active, 'varian Red harus di-supersede saat opsinya dihapus');
        $this->assertEquals(1, DB::table('product_variants')->where('product_id', $id)->where('is_active', true)->count());
    }

    public function test_removing_option_value_with_stock_rejected_422(): void
    {
        $id = $this->createIp17();
        $w = $this->warna->id;

        $red = DB::table('product_variants')->where('product_id', $id)->where('sku', 'IP17-RED')->first();
        $loc = Location::factory()->create();
        DB::table('inventories')->insert([
            'id' => \Ramsey\Uuid\Uuid::uuid7()->toString(),
            'item_id' => $red->id,
            'location_id' => $loc->id,
            'bin_id' => null,
            'on_hand' => 5,
            'on_order' => 0,
            'available' => 5,
            'avg_cost' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->putJson("/api/v1/products/{$id}", [
            'variation_types' => [['attribute_id' => $w, 'sort_order' => 0]],
            'variants' => [
                ['sku' => 'IP17-BLUE', 'sell_price' => 7000, 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Blue']]],
            ],
        ])->assertStatus(422)->assertSee('masih punya stok');

        $this->assertTrue(
            (bool) DB::table('product_variants')->where('id', $red->id)->value('is_active'),
            'varian Red harus tetap aktif karena masih punya stok'
        );
    }

    public function test_saved_option_value_with_legacy_trailing_space_is_not_rejected(): void
    {

        $id = $this->createIp17();

        $blue = DB::table('product_variants')->where('product_id', $id)->where('sku', 'IP17-BLUE')->first();
        DB::table('variant_options')
            ->where('variant_id', $blue->id)
            ->where('attribute_id', $this->warna->id)
            ->update(['value' => 'Blue ']); 

        $this->putJson("/api/v1/products/{$id}", [
            'variation_types' => [['attribute_id' => $this->warna->id, 'sort_order' => 0]],
            'variants' => [
                ['sku' => 'IP17-BLUE', 'sell_price' => 7000, 'is_active' => true,
                    'options' => [['attribute_id' => $this->warna->id, 'value' => 'Blue']]],
                ['sku' => 'IP17-RED', 'sell_price' => 7000, 'is_active' => true,
                    'options' => [['attribute_id' => $this->warna->id, 'value' => 'Red']]],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('product_variants', ['id' => $blue->id, 'is_active' => true]);
        $this->assertNull(DB::table('product_variants')->where('id', $blue->id)->value('superseded_at'));
        $this->assertEquals(2, DB::table('product_variants')->where('product_id', $id)->where('is_active', true)->count());
    }

    public function test_edit_can_add_custom_variation_type_by_name(): void
    {
        $id = $this->createIp17();
        $w = $this->warna->id;

        $this->putJson("/api/v1/products/{$id}", [
            'sku' => null,
            'variation_types' => [
                ['attribute_id' => $w, 'sort_order' => 0],
                ['name' => 'Motif', 'sort_order' => 1],
            ],
            'variants' => [
                ['sku' => 'IP17-BLUE-POLOS', 'sell_price' => 20000, 'weight' => 100, 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Blue'], ['name' => 'Motif', 'value' => 'Polos']]],
                ['sku' => 'IP17-BLUE-BATIK', 'sell_price' => 20000, 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Blue'], ['name' => 'Motif', 'value' => 'Batik']]],
                ['sku' => 'IP17-RED-POLOS', 'sell_price' => 20000, 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Red'], ['name' => 'Motif', 'value' => 'Polos']]],
                ['sku' => 'IP17-RED-BATIK', 'sell_price' => 20000, 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Red'], ['name' => 'Motif', 'value' => 'Batik']]],
            ],
        ])->assertOk();

        $motif = Attribute::where('name', 'Motif')->first();
        $this->assertNotNull($motif, 'Attribute custom "Motif" harus dibuat otomatis dari nama');
        $this->assertEquals(2, DB::table('product_variation_types')->where('product_id', $id)->count());
        $this->assertDatabaseHas('product_variation_types', ['product_id' => $id, 'attribute_id' => $motif->id]);
        $this->assertEquals(4, DB::table('product_variants')->where('product_id', $id)->where('is_active', true)->count());
    }

    public function test_edit_variant_with_null_weight_is_accepted_and_defaults(): void
    {
        $id = $this->createIp17();
        $w = $this->warna->id;

        $this->putJson("/api/v1/products/{$id}", [
            'variation_types' => [['attribute_id' => $w, 'sort_order' => 0]],
            'variants' => [
                ['sku' => 'IP17-BLUE', 'sell_price' => 7000, 'weight' => 100, 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Blue']]],
                ['sku' => 'IP17-RED', 'sell_price' => 7000, 'weight' => 100, 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Red']]],
                ['sku' => 'IP17-GREEN', 'sell_price' => 7000, 'weight' => null, 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Green']]],
            ],
        ])->assertOk();

        $new = DB::table('product_variants')->where('product_id', $id)->where('sku', 'IP17-GREEN')->first();
        $this->assertNotNull($new, 'varian baru IP17-GREEN harus ter-insert');
        $this->assertNotNull($new->weight, 'weight tidak boleh null di DB');
        $this->assertEquals(0, (int) $new->weight);
    }

    public function test_edit_reuses_sku_held_by_inactive_orphan_variant(): void
    {
        $id = $this->createIp17();
        $w = $this->warna->id;

        DB::table('product_variants')->insert([
            'id' => \Ramsey\Uuid\Uuid::uuid7()->toString(),
            'product_id' => $id,
            'sku' => 'IP17-GREEN',
            'sell_price' => 1,
            'weight' => 0,
            'is_active' => false,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->putJson("/api/v1/products/{$id}", [
            'variation_types' => [['attribute_id' => $w, 'sort_order' => 0]],
            'variants' => [
                ['sku' => 'IP17-BLUE', 'sell_price' => 7000, 'weight' => 10, 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Blue']]],
                ['sku' => 'IP17-RED', 'sell_price' => 7000, 'weight' => 10, 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Red']]],
                ['sku' => 'IP17-GREEN', 'sell_price' => 7000, 'weight' => 10, 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Green']]],
            ],
        ])->assertOk();

        $active = DB::table('product_variants')
            ->where('product_id', $id)->where('sku', 'IP17-GREEN')
            ->where('is_active', true)->whereNull('deleted_at')->first();
        $this->assertNotNull($active, 'varian aktif IP17-GREEN harus tersimpan');
    }

    public function test_edit_variant_sku_used_by_other_product_rejected_422(): void
    {
        $this->postJson('/api/v1/products', [
            'name' => 'Produk Lain',
            'sku' => 'OTHER',
            'category_id' => $this->category->id,
            'media' => [['url' => 'https://img.test/o.jpg', 'media_type' => 'image']],
            'variants' => [['sku' => 'SHARED-SKU', 'sell_price' => 1000, 'is_active' => true]],
        ])->assertCreated();

        $id = $this->createIp17();
        $w = $this->warna->id;

        $this->putJson("/api/v1/products/{$id}", [
            'variation_types' => [['attribute_id' => $w, 'sort_order' => 0]],
            'variants' => [
                ['sku' => 'IP17-BLUE', 'sell_price' => 7000, 'weight' => 10, 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Blue']]],
                ['sku' => 'IP17-RED', 'sell_price' => 7000, 'weight' => 10, 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Red']]],
                ['sku' => 'SHARED-SKU', 'sell_price' => 7000, 'weight' => 10, 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Green']]],
            ],
        ])->assertStatus(422);
    }

    public function test_detail_returns_variants_sorted_by_option_value_naturally(): void
    {
        $w = $this->warna->id;
        $res = $this->postJson('/api/v1/products', [
            'name' => 'Sortir Varian Produk Nama Panjang Sekali',
            'sku' => 'SORTP',
            'category_id' => $this->category->id,
            'media' => [['url' => 'https://img.test/s.jpg', 'media_type' => 'image']],
            'variation_types' => [['attribute_id' => $w, 'sort_order' => 0]],
            'variants' => [
                ['sku' => 'SORT-10', 'sell_price' => 1000, 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Lanyard 10']]],
                ['sku' => 'SORT-2', 'sell_price' => 1000, 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Lanyard 2']]],
                ['sku' => 'SORT-1', 'sell_price' => 1000, 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Lanyard 1']]],
            ],
        ]);
        $res->assertCreated();
        $id = $res->json('data.product_id');

        $detail = $this->getJson("/api/v1/products/{$id}");
        $detail->assertOk();

        $values = collect($detail->json('data.variants'))
            ->map(fn ($v) => $v['options'][0]['value'])
            ->all();

        $this->assertSame(['Lanyard 1', 'Lanyard 2', 'Lanyard 10'], $values);
    }

    public function test_edit_persists_weight_unit(): void
    {
        $id = $this->createIp17();
        $w = $this->warna->id;

        $this->putJson("/api/v1/products/{$id}", [
            'weight' => 60,
            'weight_unit' => 'gram',
            'variation_types' => [['attribute_id' => $w, 'sort_order' => 0]],
            'variants' => [
                ['sku' => 'IP17-BLUE', 'sell_price' => 7000, 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Blue']]],
                ['sku' => 'IP17-RED', 'sell_price' => 7000, 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Red']]],
            ],
        ])->assertOk();

        $this->assertSame('gram', DB::table('products')->where('id', $id)->value('weight_unit'));
    }

    public function test_new_combo_inherits_price_from_ancestor_when_omitted(): void
    {
        $id = $this->createIp17(price: 9000);

        $w = $this->warna->id;
        $u = $this->ukuran->id;

        $this->putJson("/api/v1/products/{$id}", [
            'variation_types' => [['attribute_id' => $w, 'sort_order' => 0], ['attribute_id' => $u, 'sort_order' => 1]],
            'variants' => [
                ['sku' => 'IP17-BLUE-256', 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Blue'], ['attribute_id' => $u, 'value' => '256/8']]],
                ['sku' => 'IP17-RED-256', 'sell_price' => 20000, 'is_active' => true,
                    'options' => [['attribute_id' => $w, 'value' => 'Red'], ['attribute_id' => $u, 'value' => '256/8']]],
            ],
        ])->assertOk();

        $blue256 = DB::table('product_variants')->where('sku', 'IP17-BLUE-256')->first();
        $this->assertEquals(9000, (int) $blue256->sell_price);
    }
}
