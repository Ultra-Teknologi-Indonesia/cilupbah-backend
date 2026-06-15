<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductNo500GuardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_non_uuid_path_ids_return_404_not_500(): void
    {
        $endpoints = [
            ['get', '/api/v1/products/master/not-a-uuid'],
            ['get', '/api/v1/products/archives/not-a-uuid'],
            ['get', '/api/v1/products/channel-products/not-a-uuid'],
            ['get', '/api/v1/raise-products/not-a-uuid'],
            ['post', '/api/v1/raise-products/not-a-uuid/raise'],
            ['delete', '/api/v1/raise-products/not-a-uuid'],
            ['get', '/api/v1/products/not-a-uuid'],
            ['put', '/api/v1/products/not-a-uuid'],
            ['delete', '/api/v1/products/not-a-uuid'],
            ['post', '/api/v1/products/not-a-uuid/approve'],
            ['post', '/api/v1/upload-histories/not-a-uuid/re-upload'],
            ['delete', '/api/v1/upload-histories/not-a-uuid'],
        ];

        foreach ($endpoints as [$method, $uri]) {
            $response = $this->actingAs($this->user, 'sanctum')->json($method, $uri);

            $this->assertSame(
                404,
                $response->getStatusCode(),
                "{$method} {$uri} harus 404, dapat {$response->getStatusCode()}"
            );
        }
    }

    public function test_non_numeric_master_data_ids_return_404_not_500(): void
    {
        $endpoints = [
            ['get', '/api/v1/categories/abc'],
            ['put', '/api/v1/categories/abc'],
            ['delete', '/api/v1/categories/abc'],
            ['get', '/api/v1/brands/abc'],
            ['get', '/api/v1/attributes/abc'],
            ['post', '/api/v1/categories/abc/map-channel'],
            ['post', '/api/v1/attributes/abc/map-channel'],
            ['get', '/api/v1/inventory/categories/category-map/abc'],
        ];

        foreach ($endpoints as [$method, $uri]) {
            $response = $this->actingAs($this->user, 'sanctum')->json($method, $uri);

            $this->assertSame(
                404,
                $response->getStatusCode(),
                "{$method} {$uri} harus 404, dapat {$response->getStatusCode()}"
            );
        }
    }

    public function test_non_numeric_foreign_keys_in_body_return_422_not_500(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Produk Uji',
                'category_id' => 'abc',
                'brand_id' => 'abc',
                'variants' => [['sku' => 'SKU-GUARD-1', 'sell_price' => 1000]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category_id', 'brand_id']);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/categories', ['name' => 'Kategori Uji', 'parent_id' => 'abc'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Produk Uji 2',
                'category_id' => 1,
                'specifications' => [['attribute_id' => 'abc']],
                'variants' => [['sku' => 'SKU-GUARD-2', 'sell_price' => 1000]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['specifications.0.attribute_id']);
    }
}
