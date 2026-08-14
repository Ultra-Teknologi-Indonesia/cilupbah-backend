<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductImportBatch;
use Modules\Product\Models\ProductVariant;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
    }

    private function makeUpload(array $headings, array $rows, string $name = 'import.xlsx'): UploadedFile
    {
        $export = new class($headings, $rows) implements FromArray, WithHeadings {
            public function __construct(public array $h, public array $r) {}
            public function array(): array { return $this->r; }
            public function headings(): array { return $this->h; }
        };

        $relative = 'tmp/' . Str::uuid() . '.xlsx';
        Excel::store($export, $relative, 'local');

        return new UploadedFile(Storage::disk('local')->path($relative), $name, null, null, true);
    }

    public function test_import_single_processes_valid_and_records_invalid(): void
    {
        $headings = ['item_group_name', 'item_code', 'sell_price', 'category'];
        $rows = [
            ['Kaos Polos', 'KP-HITAM-M', 75000, 'Fashion'],
            ['Kaos Polos', 'KP-PUTIH-L', 80000, 'Fashion'],
            ['Celana', '', 50000, 'Fashion'],
            ['Topi', 'TP-1', 'bukan-angka', 'Fashion'],       
        ];

        $file = $this->makeUpload($headings, $rows);

        $response = $this->postJson('/api/v1/products/import/single', ['file' => $file]);

        $response->assertStatus(202);
        $batchId = $response->json('data.id');
        $this->assertNotNull($batchId);

        $batch = ProductImportBatch::find($batchId);
        $this->assertSame(ProductImportBatch::STATE_DONE_WITH_ERRORS, $batch->state);
        $this->assertSame(4, $batch->total_rows);
        $this->assertSame(2, $batch->success_rows);
        $this->assertSame(2, $batch->failed_rows);

        $this->assertDatabaseHas('products', ['name' => 'Kaos Polos', 'status' => Product::STATUS_MASTER]);
        $this->assertDatabaseHas('product_variants', ['sku' => 'KP-HITAM-M']);
        $this->assertDatabaseHas('product_variants', ['sku' => 'KP-PUTIH-L']);

        $this->assertSame(1, Product::where('name', 'Kaos Polos')->count());

        $this->assertDatabaseHas('product_import_errors', ['import_batch_id' => $batchId, 'attribute' => 'item_code']);
        $this->assertDatabaseHas('product_import_errors', ['import_batch_id' => $batchId, 'attribute' => 'sell_price']);
    }

    public function test_import_single_all_valid_is_done(): void
    {
        $file = $this->makeUpload(
            ['item_group_name', 'item_code', 'sell_price'],
            [['Mug', 'MUG-1', 25000]]
        );

        $response = $this->postJson('/api/v1/products/import/single', ['file' => $file]);
        $response->assertStatus(202);

        $batch = ProductImportBatch::find($response->json('data.id'));
        $this->assertSame(ProductImportBatch::STATE_DONE, $batch->state);
        $this->assertSame(1, $batch->success_rows);
        $this->assertSame(0, $batch->failed_rows);
    }

    public function test_import_bundle_links_components_and_flags_missing(): void
    {
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Cat']);

        $cat = \Modules\Product\Models\Category::firstOrCreate(['name' => 'General']);
        $bundle = Product::create(['name' => 'Paket Hemat', 'category_id' => $cat->id, 'status' => Product::STATUS_MASTER, 'is_active' => true]);
        $bundleVariant = ProductVariant::create(['product_id' => $bundle->id, 'sku' => 'BUNDLE-A', 'sell_price' => 100000, 'is_active' => true]);

        $comp = Product::create(['name' => 'Komponen', 'category_id' => $cat->id, 'status' => Product::STATUS_MASTER, 'is_active' => true]);
        $compVariant = ProductVariant::create(['product_id' => $comp->id, 'sku' => 'COMP-1', 'sell_price' => 40000, 'is_active' => true]);

        $file = $this->makeUpload(
            ['item_code', 'sku_composition', 'qty'],
            [
                ['BUNDLE-A', 'COMP-1', 2],    
                ['BUNDLE-A', 'NOPE', 1],      
            ]
        );

        $response = $this->postJson('/api/v1/products/import/bundle', ['file' => $file]);
        $response->assertStatus(202);

        $batch = ProductImportBatch::find($response->json('data.id'));
        $this->assertSame(ProductImportBatch::STATE_DONE_WITH_ERRORS, $batch->state);
        $this->assertSame(1, $batch->success_rows);
        $this->assertSame(1, $batch->failed_rows);

        $this->assertDatabaseHas('product_bundle_items', [
            'bundle_product_id' => $bundle->id,
            'component_variant_id' => $compVariant->id,
            'qty' => 2,
        ]);
    }

    public function test_import_bundle_creates_new_bundle_product_if_bundle_name_supplied(): void
    {
        $cat = \Modules\Product\Models\Category::firstOrCreate(['name' => 'General']);
        $comp = Product::create(['name' => 'Comp Item', 'category_id' => $cat->id, 'is_bundle' => false, 'status' => Product::STATUS_MASTER]);
        $compVariant = ProductVariant::create(['product_id' => $comp->id, 'sku' => 'COMP-NEW-1', 'sell_price' => 20000, 'is_active' => true]);

        $file = $this->makeUpload(
            ['item_code', 'bundle_name', 'category', 'sell_price', 'description', 'sku_composition', 'qty'],
            [
                ['NEW-BUNDLE-SKU', 'Bundle Super Hemat', 'Fashion Pria', 50000, 'Deskripsi Paket', 'COMP-NEW-1', 2],
            ]
        );

        $response = $this->postJson('/api/v1/products/import/bundle', ['file' => $file]);
        $response->assertStatus(202);

        $batch = ProductImportBatch::find($response->json('data.id'));
        $this->assertSame(ProductImportBatch::STATE_DONE, $batch->state);

        $this->assertDatabaseHas('products', [
            'name' => 'Bundle Super Hemat',
            'is_bundle' => true,
        ]);
        $this->assertDatabaseHas('product_variants', [
            'sku' => 'NEW-BUNDLE-SKU',
            'sell_price' => 50000,
        ]);
    }

    public function test_import_single_saves_dimensions_and_handles_blank_cells(): void
    {
        $headings = [
            'item_group_name', 'item_code', 'sell_price', 'barcode',
            'package_weight', 'package_length', 'package_width', 'package_height',
            'image_url1',
        ];
        $rows = [
            ['Box Premium', 'BOX-01', 150000, '8991234567890', 1.5, 30, 20, 10, ''],
        ];

        $file = $this->makeUpload($headings, $rows);
        $response = $this->postJson('/api/v1/products/import/single', ['file' => $file]);
        $response->assertStatus(202);

        $batch = ProductImportBatch::find($response->json('data.id'));
        $this->assertSame(ProductImportBatch::STATE_DONE, $batch->state);
        $this->assertSame(1, $batch->success_rows);

        $this->assertDatabaseHas('products', [
            'name' => 'Box Premium',
            'weight' => 1.5,
            'length' => 30,
            'width' => 20,
            'height' => 10,
        ]);

        $this->assertDatabaseHas('product_variants', [
            'sku' => 'BOX-01',
            'weight' => 1.5,
            'length' => 30,
            'width' => 20,
            'height' => 10,
        ]);
    }

    public function test_import_bundle_rejects_bundle_in_bundle_and_same_sku(): void
    {
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Cat']);

        $bundleParent = Product::create(['name' => 'Bundle Utama', 'category_id' => 1, 'status' => Product::STATUS_MASTER, 'is_active' => true]);
        ProductVariant::create(['product_id' => $bundleParent->id, 'sku' => 'BUNDLE-PARENT', 'sell_price' => 200000, 'is_active' => true]);

        $bundleChild = Product::create(['name' => 'Bundle Anak', 'category_id' => 1, 'status' => Product::STATUS_MASTER, 'is_active' => true, 'is_bundle' => true]);
        ProductVariant::create(['product_id' => $bundleChild->id, 'sku' => 'BUNDLE-CHILD', 'sell_price' => 100000, 'is_active' => true]);

        $file = $this->makeUpload(
            ['item_code', 'sku_composition', 'qty'],
            [
                ['BUNDLE-PARENT', 'BUNDLE-CHILD', 1],
                ['BUNDLE-PARENT', 'BUNDLE-PARENT', 1],
            ]
        );

        $response = $this->postJson('/api/v1/products/import/bundle', ['file' => $file]);
        $response->assertStatus(202);

        $batch = ProductImportBatch::find($response->json('data.id'));
        $this->assertSame(ProductImportBatch::STATE_FAILED, $batch->state);
        $this->assertSame(0, $batch->success_rows);
        $this->assertSame(2, $batch->failed_rows);
    }

    public function test_invalid_file_type_rejected(): void
    {
        $file = UploadedFile::fake()->create('data.txt', 10, 'text/plain');

        $this->postJson('/api/v1/products/import/single', ['file' => $file])
            ->assertStatus(422);
    }

    public function test_errors_endpoint_lists_row_errors(): void
    {
        $file = $this->makeUpload(
            ['item_group_name', 'item_code', 'sell_price'],
            [['X', 'X-1', 'NaN']]
        );
        $batchId = $this->postJson('/api/v1/products/import/single', ['file' => $file])->json('data.id');

        $this->getJson("/api/v1/products/import/batches/{$batchId}/errors")
            ->assertStatus(200)
            ->assertJsonPath('data.0.attribute', 'sell_price');
    }

    public function test_show_and_list_batches(): void
    {
        $file = $this->makeUpload(['item_group_name', 'item_code', 'sell_price'], [['A', 'A-1', 1000]]);
        $batchId = $this->postJson('/api/v1/products/import/single', ['file' => $file])->json('data.id');

        $this->getJson("/api/v1/products/import/batches/{$batchId}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $batchId);

        $this->getJson('/api/v1/products/import/batches')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_download_single_template(): void
    {
        $response = $this->get('/api/v1/products/import/template/single');
        $response->assertStatus(200);
        $this->assertStringContainsString('Template_Import_Product.xlsx', $response->headers->get('content-disposition'));
    }

    public function test_download_bundle_template(): void
    {
        $response = $this->get('/api/v1/products/import/template/bundle');
        $response->assertStatus(200);
        $this->assertStringContainsString('Template_Import_Bundle.xlsx', $response->headers->get('content-disposition'));
    }
}
