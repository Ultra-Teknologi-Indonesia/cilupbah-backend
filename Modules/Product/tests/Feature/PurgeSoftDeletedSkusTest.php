<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Tests\TestCase;

class PurgeSoftDeletedSkusTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_remove_soft_deleted_sku(): void
    {
        $variant = $this->makeDeletedVariant('PURGE-DRY-RUN');

        $this->artisan('products:purge-soft-deleted-skus')
            ->assertSuccessful();

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id]);
    }

    public function test_apply_removes_unreferenced_soft_deleted_sku(): void
    {
        $variant = $this->makeDeletedVariant('PURGE-APPLY');

        $this->artisan('products:purge-soft-deleted-skus', ['--apply' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('product_variants', ['id' => $variant->id]);
    }

    private function makeDeletedVariant(string $sku): ProductVariant
    {
        $product = Product::create([
            'category_id' => Category::create(['name' => 'Purge SKU Category'])->id,
            'name' => 'Master lama '.$sku,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'is_active' => true,
        ]);

        $variant->delete();
        $product->delete();

        return $variant;
    }
}
