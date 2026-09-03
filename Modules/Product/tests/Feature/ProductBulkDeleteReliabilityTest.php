<?php

declare(strict_types=1);

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\Inventory;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductDeleteAudit;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Services\MediaCleanupService;
use Modules\Warehouse\Models\Location;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class ProductBulkDeleteReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createPrivilegedUser();
        $this->actingAs($this->user);
        $this->category = Category::create([
            'id' => (string) Uuid::uuid7(),
            'name' => 'Kategori bulk delete',
        ]);
    }

    public function test_bulk_delete_is_atomic_and_records_audit(): void
    {
        $first = $this->createProduct('Produk Bulk 1', 'BULK-DELETE-1');
        $second = $this->createProduct('Produk Bulk 2', 'BULK-DELETE-2');

        $response = $this->postJson('/api/v1/products/bulk-delete', [
            'ids' => [$first->id, $second->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.success', 2)
            ->assertJsonPath('data.failed', 0)
            ->assertJsonPath('data.batch_id', fn ($value): bool => is_string($value) && $value !== '');

        $this->assertSoftDeleted('products', ['id' => $first->id]);
        $this->assertSoftDeleted('products', ['id' => $second->id]);
        $this->assertDatabaseHas('product_delete_audits', [
            'actor_id' => $this->user->id,
            'status' => ProductDeleteAudit::STATUS_SUCCEEDED,
            'requested_count' => 2,
        ]);
    }

    public function test_bulk_delete_blocks_the_entire_batch_when_one_product_has_stock(): void
    {
        $first = $this->createProduct('Produk Bulk Aman', 'BULK-BLOCK-1');
        $second = $this->createProduct('Produk Bulk Berstok', 'BULK-BLOCK-2');
        $variant = $second->variants()->firstOrFail();
        $location = Location::create([
            'location_code' => 'BULK-DELETE-WH',
            'location_name' => 'Gudang Bulk Delete',
            'location_type' => 'warehouse',
        ]);

        Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'on_hand' => 3,
            'available' => 3,
        ]);

        $response = $this->postJson('/api/v1/products/bulk-delete', [
            'ids' => [$first->id, $second->id],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'PRODUCT_BULK_DELETE_BLOCKED')
            ->assertJsonPath('errors.items.0', fn ($value): bool => str_contains($value, 'Produk Bulk Berstok'));

        $this->assertNotSoftDeleted('products', ['id' => $first->id]);
        $this->assertNotSoftDeleted('products', ['id' => $second->id]);
        $this->assertDatabaseHas('product_delete_audits', [
            'actor_id' => $this->user->id,
            'status' => ProductDeleteAudit::STATUS_BLOCKED,
            'failure_code' => 'BUSINESS_RULE',
        ]);
    }

    public function test_unexpected_failure_rolls_back_the_batch_and_returns_reference(): void
    {
        $first = $this->createProduct('Produk Rollback 1', 'BULK-ROLLBACK-1');
        $second = $this->createProduct('Produk Rollback 2', 'BULK-ROLLBACK-2');

        $this->mock(MediaCleanupService::class, function ($mock): void {
            $mock->shouldReceive('collectByProduct')
                ->once()
                ->andThrow(new \RuntimeException('simulated cleanup preparation failure'));
        });

        $response = $this->postJson('/api/v1/products/bulk-delete', [
            'ids' => [$first->id, $second->id],
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('code', 'PRODUCT_BULK_DELETE_FAILED')
            ->assertJsonPath('request_id', fn ($value): bool => is_string($value) && $value !== '');

        $this->assertNotSoftDeleted('products', ['id' => $first->id]);
        $this->assertNotSoftDeleted('products', ['id' => $second->id]);
        $this->assertDatabaseHas('product_delete_audits', [
            'actor_id' => $this->user->id,
            'status' => ProductDeleteAudit::STATUS_FAILED,
            'failure_code' => 'UNEXPECTED_ERROR',
        ]);
    }

    public function test_media_cleanup_failure_does_not_turn_a_successful_delete_into_server_error(): void
    {
        $product = $this->createProduct('Produk Cleanup Retry', 'BULK-CLEANUP-1');

        $this->mock(MediaCleanupService::class, function ($mock): void {
            $mock->shouldReceive('collectByProduct')->once()->andReturn([]);
            $mock->shouldReceive('pruneOrphans')
                ->once()
                ->andThrow(new \RuntimeException('simulated storage failure'));
        });

        $response = $this->postJson('/api/v1/products/bulk-delete', [
            'ids' => [$product->id],
        ]);

        $response->assertOk()->assertJsonPath('data.success', 1);
        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertDatabaseHas('product_delete_audits', [
            'status' => ProductDeleteAudit::STATUS_SUCCEEDED,
            'media_cleanup_status' => ProductDeleteAudit::MEDIA_CLEANUP_FAILED,
        ]);
    }

    private function createProduct(string $name, string $sku): Product
    {
        $product = Product::create([
            'id' => (string) Uuid::uuid7(),
            'name' => $name,
            'sku' => $sku,
            'category_id' => $this->category->id,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);

        ProductVariant::create([
            'id' => (string) Uuid::uuid7(),
            'product_id' => $product->id,
            'sku' => $sku.'-VAR',
            'sell_price' => 1000,
            'is_active' => true,
        ]);

        return $product->fresh();
    }
}
