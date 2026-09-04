<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Product\Exports\ProductCatalogCsvExport;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductBundleItem;
use Modules\Product\Models\ProductVariant;
use Modules\Report\Jobs\RunExportJob;
use Modules\Report\Models\ExportJob;
use Tests\TestCase;

class ProductCatalogExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createPrivilegedUser();
        Queue::fake();
    }

    public function test_catalog_export_is_queued_on_its_dedicated_queue(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            '/api/v1/products/master/export',
            [
                'search' => 'case',
                'status' => Product::STATUS_MASTER,
                'type' => 'satuan',
            ],
        );

        $response->assertStatus(202)->assertJsonPath('data.status', ExportJob::STATUS_QUEUED);

        $jobId = $response->json('data.export_id');
        $this->assertDatabaseHas('export_jobs', [
            'id' => $jobId,
            'type' => 'product-catalog-csv',
            'status' => ExportJob::STATUS_QUEUED,
        ]);

        Queue::assertPushed(RunExportJob::class, function (RunExportJob $job) use ($jobId): bool {
            return $job->exportJobId === $jobId
                && $job->connection === 'redis-long'
                && $job->queue === 'catalog-exports';
        });
    }

    public function test_catalog_export_rejects_an_unsupported_filter(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products/master/export', ['type' => 'unknown'])
            ->assertStatus(422);

        $this->assertDatabaseCount('export_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_catalog_export_has_the_business_csv_columns(): void
    {
        $headings = (new ProductCatalogCsvExport)->headings();

        $this->assertCount(16, $headings);
        $this->assertSame('Stock', $headings[15]);
        $this->assertSame('Name', $headings[0]);
        $this->assertNotContains('Item ID', $headings);
        $this->assertNotContains('Item Group ID', $headings);
    }

    public function test_catalog_query_streams_a_variant_and_emits_zero_stock(): void
    {
        $category = Category::create(['name' => 'Catalog Test', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produk Catalog',
            'status' => Product::STATUS_MASTER,
            'is_bundle' => false,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'CATALOG-TEST-001',
            'sell_price' => 12500,
            'is_active' => true,
        ]);

        $rows = (new ProductCatalogCsvExport(['status' => Product::STATUS_MASTER]))
            ->query()
            ->where('sku', $variant->sku)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame('CATALOG-TEST-001', $rows[0]->sku);
        $this->assertSame(0, (int) $rows[0]->stock);
    }

    public function test_catalog_query_excludes_active_variant_without_sku(): void
    {
        $category = Category::create(['name' => 'Catalog Empty SKU Test', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produk Catalog Tanpa SKU',
            'status' => Product::STATUS_MASTER,
            'is_bundle' => false,
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => null,
            'is_active' => true,
        ]);

        $rows = (new ProductCatalogCsvExport(['status' => Product::STATUS_MASTER]))
            ->query()
            ->where('name', $product->name)
            ->get();

        $this->assertCount(0, $rows);
    }

    public function test_catalog_map_converts_empty_numeric_values_to_zero(): void
    {
        $row = (object) [
            'name' => 'Produk',
            'sku' => '',
            'category_name' => '',
            'variation' => '',
            'description' => '',
            'package_weight' => null,
            'package_width' => '',
            'package_height' => null,
            'package_length' => '',
            'sell_price' => null,
            'image_1' => '',
            'image_2' => null,
            'image_3' => '',
            'image_4' => null,
            'image_5' => '',
            'stock' => null,
        ];

        $mapped = (new ProductCatalogCsvExport)->map($row);

        foreach ([5, 6, 7, 8, 9, 15] as $index) {
            $this->assertSame(0, $mapped[$index]);
        }

        $this->assertSame('', $mapped[1]);
        $this->assertSame('', $mapped[10]);
    }

    public function test_catalog_query_exports_bundle_components_without_technical_sku(): void
    {
        $category = Category::create(['name' => 'Bundle Test', 'is_active' => true]);
        $componentProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'Komponen Bundle',
            'status' => Product::STATUS_MASTER,
        ]);
        $component = ProductVariant::create([
            'product_id' => $componentProduct->id,
            'sku' => 'COMPONENT-001',
            'sell_price' => 3000,
        ]);
        $bundle = Product::create([
            'category_id' => $category->id,
            'name' => 'Bundle Catalog',
            'sku' => '__bundle__catalog-test',
            'status' => Product::STATUS_MASTER,
            'is_bundle' => true,
        ]);
        ProductBundleItem::create([
            'bundle_product_id' => $bundle->id,
            'component_variant_id' => $component->id,
            'qty' => 2,
        ]);

        $rows = (new ProductCatalogCsvExport([
            'status' => Product::STATUS_MASTER,
            'type' => 'bundle',
        ]))->query()->get();

        $this->assertCount(1, $rows);
        $this->assertSame('COMPONENT-001', $rows[0]->sku);
    }
}
