<?php

namespace Modules\Product\Tests\Feature;

use Tests\TestCase;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class ProductLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Cat']);
        DB::table('brands')->insertOrIgnore(['id' => 1, 'name' => 'Brand']);
    }

    /**
     * Buat produk lengkap (variant + media) pada status tertentu.
     */
    private function makeProduct(string $status, bool $withVariant = true, bool $withMedia = true): Product
    {
        $product = Product::create([
            'name' => 'Product ' . uniqid(),
            'category_id' => 1,
            'status' => $status,
            'is_active' => true,
        ]);

        if ($withVariant) {
            ProductVariant::create([
                'product_id' => $product->id,
                'sku' => 'SKU-' . uniqid(),
                'sell_price' => 100000,
                'is_active' => true,
            ]);
        }

        if ($withMedia) {
            ProductMedia::create([
                'product_id' => $product->id,
                'media_type' => 'image',
                'url' => 'https://example.com/img.jpg',
                'is_primary' => true,
                'sort_order' => 1,
            ]);
        }

        return $product;
    }

    // ==================== APPROVE ====================

    public function test_approve_in_review_to_master()
    {
        $product = $this->makeProduct(Product::STATUS_IN_REVIEW);

        $response = $this->postJson("/api/v1/products/{$product->id}/approve");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'master');

        $product->refresh();
        $this->assertSame('master', $product->status);
        $this->assertNotNull($product->verified_at);
    }

    public function test_approve_fails_when_not_in_review()
    {
        $product = $this->makeProduct(Product::STATUS_MASTER);

        $response = $this->postJson("/api/v1/products/{$product->id}/approve");

        $response->assertStatus(422);
        $response->assertJsonPath('status', 'error');
    }

    public function test_approve_fails_without_variant()
    {
        $product = $this->makeProduct(Product::STATUS_IN_REVIEW, withVariant: false);

        $response = $this->postJson("/api/v1/products/{$product->id}/approve");

        $response->assertStatus(422);
    }

    public function test_approve_fails_without_image()
    {
        $product = $this->makeProduct(Product::STATUS_IN_REVIEW, withMedia: false);

        $response = $this->postJson("/api/v1/products/{$product->id}/approve");

        $response->assertStatus(422);
    }

    public function test_approve_not_found_returns_404()
    {
        $response = $this->postJson('/api/v1/products/bogus-id/approve');

        $response->assertStatus(404);
    }

    // ==================== REJECT ====================

    public function test_reject_in_review_to_download()
    {
        $product = $this->makeProduct(Product::STATUS_IN_REVIEW);

        $response = $this->postJson("/api/v1/products/{$product->id}/reject");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'download');
        $this->assertSame('download', $product->fresh()->status);
    }

    public function test_reject_fails_when_not_in_review()
    {
        $product = $this->makeProduct(Product::STATUS_MASTER);

        $response = $this->postJson("/api/v1/products/{$product->id}/reject");

        $response->assertStatus(422);
    }

    // ==================== ARCHIVE ====================

    public function test_archive_master()
    {
        $product = $this->makeProduct(Product::STATUS_MASTER);

        $response = $this->postJson("/api/v1/products/{$product->id}/archive", ['reason' => 'Stok habis']);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'archived');

        $product->refresh();
        $this->assertSame('archived', $product->status);
        $this->assertNotNull($product->archived_at);
        $this->assertSame('Stok habis', $product->archive_reason);
    }

    public function test_archive_fails_when_not_master()
    {
        $product = $this->makeProduct(Product::STATUS_IN_REVIEW);

        $response = $this->postJson("/api/v1/products/{$product->id}/archive");

        $response->assertStatus(422);
    }

    public function test_archive_fails_when_already_archived()
    {
        $product = $this->makeProduct(Product::STATUS_ARCHIVED);

        $response = $this->postJson("/api/v1/products/{$product->id}/archive");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Produk sudah dalam status Arsip');
    }

    // ==================== RESTORE ====================

    public function test_restore_archived_to_master()
    {
        $product = $this->makeProduct(Product::STATUS_ARCHIVED);
        $product->update(['archived_at' => now(), 'archive_reason' => 'X']);

        $response = $this->postJson("/api/v1/products/{$product->id}/restore");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'master');

        $product->refresh();
        $this->assertSame('master', $product->status);
        $this->assertNull($product->archived_at);
        $this->assertNull($product->archive_reason);
    }

    public function test_restore_fails_when_not_archived()
    {
        $product = $this->makeProduct(Product::STATUS_MASTER);

        $response = $this->postJson("/api/v1/products/{$product->id}/restore");

        $response->assertStatus(422);
    }

    // ==================== UPDATE ====================

    public function test_update_product()
    {
        $product = $this->makeProduct(Product::STATUS_MASTER);

        $response = $this->putJson("/api/v1/products/{$product->id}", [
            'name' => 'Updated Name',
            'description' => 'Updated description',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'Updated Name');
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_update_not_found_returns_404()
    {
        $response = $this->putJson('/api/v1/products/bogus-id', ['name' => 'X']);

        $response->assertStatus(404);
    }
}
