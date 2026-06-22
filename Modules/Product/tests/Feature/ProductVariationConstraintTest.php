<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Attribute;
use Modules\Product\Models\Category;
use Tests\TestCase;

class ProductVariationConstraintTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Category $category;
    protected Attribute $warna;
    protected Attribute $ukuran;
    protected Attribute $bahan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->category = Category::create(['name' => 'Baju']);

        $this->warna = Attribute::firstOrCreate(['name' => 'Warna'], ['type' => 'sales']);
        $this->ukuran = Attribute::firstOrCreate(['name' => 'Ukuran'], ['type' => 'sales']);
        $this->bahan = Attribute::firstOrCreate(['name' => 'Bahan'], ['type' => 'sales']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Kaos Polos',
            'sku' => 'KAOS-01',
            'category_id' => $this->category->id,
            'media' => [['url' => 'https://img.test/a.jpg', 'media_type' => 'image']],
            'variants' => [[
                'sku' => 'KAOS-VAR-01',
                'sell_price' => 50000,
            ]],
        ], $overrides);
    }

    public function test_three_variation_types_rejected_422(): void
    {
        $this->postJson('/api/v1/products', $this->payload([
            'variation_types' => [
                ['attribute_id' => $this->warna->id],
                ['attribute_id' => $this->ukuran->id],
                ['attribute_id' => $this->bahan->id],
            ],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('variation_types');
    }

    public function test_duplicate_variation_type_rejected_422(): void
    {
        $this->postJson('/api/v1/products', $this->payload([
            'variation_types' => [
                ['attribute_id' => $this->warna->id],
                ['attribute_id' => $this->warna->id],
            ],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('variation_types.1.attribute_id');
    }

    public function test_variant_option_outside_declared_types_rejected_422(): void
    {
        $this->postJson('/api/v1/products', $this->payload([
            'variation_types' => [
                ['attribute_id' => $this->warna->id],
            ],
            'variants' => [[
                'sku' => 'KAOS-VAR-01',
                'sell_price' => 50000,

                'options' => [['attribute_id' => $this->ukuran->id, 'value' => 'L']],
            ]],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Opsi varian memakai jenis yang tidak ada di variation_types.');
    }

    public function test_duplicate_variant_combination_rejected_422(): void
    {
        $this->postJson('/api/v1/products', $this->payload([
            'variation_types' => [
                ['attribute_id' => $this->warna->id],
                ['attribute_id' => $this->ukuran->id],
            ],
            'variants' => [
                [
                    'sku' => 'KAOS-MERAH-L',
                    'sell_price' => 50000,
                    'options' => [
                        ['attribute_id' => $this->warna->id, 'value' => 'Merah'],
                        ['attribute_id' => $this->ukuran->id, 'value' => 'L'],
                    ],
                ],
                [
                    'sku' => 'KAOS-MERAH-L-DUP',
                    'sell_price' => 55000,
                    'options' => [
                        ['attribute_id' => $this->ukuran->id, 'value' => 'L'],
                        ['attribute_id' => $this->warna->id, 'value' => 'Merah'],
                    ],
                ],
            ],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kombinasi varian tidak boleh sama dengan varian lain.');
    }

    public function test_valid_two_variation_types_succeeds(): void
    {
        $res = $this->postJson('/api/v1/products', $this->payload([
            'variation_types' => [
                ['attribute_id' => $this->warna->id, 'sort_order' => 0],
                ['attribute_id' => $this->ukuran->id, 'sort_order' => 1],
            ],
            'variants' => [
                [
                    'sku' => 'KAOS-MERAH-L',
                    'sell_price' => 50000,
                    'options' => [
                        ['attribute_id' => $this->warna->id, 'value' => 'Merah'],
                        ['attribute_id' => $this->ukuran->id, 'value' => 'L'],
                    ],
                ],
                [
                    'sku' => 'KAOS-BIRU-M',
                    'sell_price' => 50000,
                    'options' => [
                        ['attribute_id' => $this->warna->id, 'value' => 'Biru'],
                        ['attribute_id' => $this->ukuran->id, 'value' => 'M'],
                    ],
                ],
            ],
        ]));

        $res->assertCreated();
        $productId = $res->json('data.product_id');

        $this->assertEquals(2, DB::table('product_variation_types')->where('product_id', $productId)->count());
        $this->assertDatabaseHas('product_variants', ['sku' => 'KAOS-MERAH-L']);
        $this->assertDatabaseHas('product_variants', ['sku' => 'KAOS-BIRU-M']);
    }
}
