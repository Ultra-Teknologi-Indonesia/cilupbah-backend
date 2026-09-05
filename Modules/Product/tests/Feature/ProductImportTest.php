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

    public function test_import_single_preview_and_confirm_workflow(): void
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
        $this->assertSame(ProductImportBatch::STATE_PREVIEWED, $batch->state);
        $this->assertSame(4, $batch->total_rows);
        $this->assertSame(2, $batch->success_rows);
        $this->assertSame(2, $batch->failed_rows);

        $rowsResponse = $this->getJson("/api/v1/products/import/batches/{$batchId}/rows");
        $rowsResponse->assertStatus(200);
        $rowsResponse->assertJsonPath('meta.total', 4);

        $validRowsResponse = $this->getJson("/api/v1/products/import/batches/{$batchId}/rows?status=valid");
        $validRowsResponse->assertStatus(200);
        $validRowsResponse->assertJsonPath('meta.total', 2);

        $this->assertDatabaseMissing('products', ['name' => 'Kaos Polos']);

        $confirmResponse = $this->postJson("/api/v1/products/import/batches/{$batchId}/confirm");
        $confirmResponse->assertStatus(202);

        $batch->refresh();
        $this->assertSame(ProductImportBatch::STATE_DONE, $batch->state);
        $this->assertSame(2, $batch->processed_rows);

        $this->assertDatabaseHas('products', ['name' => 'Kaos Polos', 'status' => Product::STATUS_MASTER]);
        $this->assertDatabaseHas('product_variants', ['sku' => 'KP-HITAM-M']);
        $this->assertDatabaseHas('product_variants', ['sku' => 'KP-PUTIH-L']);
        $this->assertSame(1, Product::where('name', 'Kaos Polos')->count());

        $this->assertDatabaseHas('product_import_errors', ['import_batch_id' => $batchId, 'attribute' => 'item_code']);
        $this->assertDatabaseHas('product_import_errors', ['import_batch_id' => $batchId, 'attribute' => 'sell_price']);
    }

    public function test_import_single_all_valid_is_previewed_and_confirmed(): void
    {
        $file = $this->makeUpload(
            ['item_group_name', 'item_code', 'sell_price'],
            [['Mug', 'MUG-1', 25000]]
        );

        $response = $this->postJson('/api/v1/products/import/single', ['file' => $file]);
        $response->assertStatus(202);

        $batchId = $response->json('data.id');
        $batch = ProductImportBatch::find($batchId);
        $this->assertSame(ProductImportBatch::STATE_PREVIEWED, $batch->state);
        $this->assertSame(1, $batch->success_rows);
        $this->assertSame(0, $batch->failed_rows);

        $confirmResponse = $this->postJson("/api/v1/products/import/batches/{$batchId}/confirm");
        $confirmResponse->assertStatus(202);

        $batch->refresh();
        $this->assertSame(ProductImportBatch::STATE_DONE, $batch->state);
        $this->assertDatabaseHas('products', ['name' => 'Mug', 'sku' => 'MUG-1']);
    }

    public function test_import_bundle_preview_and_confirm_workflow(): void
    {
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Cat']);

        $cat = \Modules\Product\Models\Category::firstOrCreate(['name' => 'General']);
        $bundle = Product::create(['name' => 'Paket Hemat', 'category_id' => $cat->id, 'sku' => 'BUNDLE-A', 'status' => Product::STATUS_MASTER, 'is_bundle' => true, 'is_active' => true]);
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

        $batchId = $response->json('data.id');
        $batch = ProductImportBatch::find($batchId);
        $this->assertSame(ProductImportBatch::STATE_PREVIEWED, $batch->state);
        $this->assertSame(1, $batch->success_rows);
        $this->assertSame(1, $batch->failed_rows);

        $confirmResponse = $this->postJson("/api/v1/products/import/batches/{$batchId}/confirm");
        $confirmResponse->assertStatus(202);

        $batch->refresh();
        $this->assertSame(ProductImportBatch::STATE_DONE, $batch->state);

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

        $batchId = $response->json('data.id');
        $batch = ProductImportBatch::find($batchId);
        $this->assertSame(ProductImportBatch::STATE_PREVIEWED, $batch->state);

        $this->postJson("/api/v1/products/import/batches/{$batchId}/confirm")->assertStatus(202);

        $batch->refresh();
        $this->assertSame(ProductImportBatch::STATE_DONE, $batch->state);

        $this->assertDatabaseHas('products', [
            'name' => 'Bundle Super Hemat',
            'sku' => 'NEW-BUNDLE-SKU',
            'is_bundle' => true,
        ]);
        $createdBundle = Product::where('sku', 'NEW-BUNDLE-SKU')->firstOrFail();
        $this->assertDatabaseHas('product_variants', [
            'product_id' => $createdBundle->id,
            'sku' => '__bundle__'.$createdBundle->id,
            'sell_price' => 50000,
            'is_internal' => true,
        ]);
    }

    public function test_import_bundle_reuses_existing_bundle_by_product_sku(): void
    {
        $cat = \Modules\Product\Models\Category::firstOrCreate(['name' => 'General']);
        $bundle = Product::create([
            'name' => 'Bundle Lama',
            'category_id' => $cat->id,
            'sku' => 'EXISTING-BUNDLE',
            'is_bundle' => true,
            'is_active' => true,
            'status' => Product::STATUS_MASTER,
        ]);
        $component = Product::create([
            'name' => 'Komponen Lama',
            'category_id' => $cat->id,
            'is_bundle' => false,
            'status' => Product::STATUS_MASTER,
        ]);
        $componentVariant = ProductVariant::create([
            'product_id' => $component->id,
            'sku' => 'EXISTING-COMPONENT',
            'is_active' => true,
        ]);
        $before = Product::count();

        $file = $this->makeUpload(
            ['item_code', 'bundle_name', 'sku_composition', 'qty'],
            [['EXISTING-BUNDLE', 'Nama Baru Tidak Menggandakan', 'EXISTING-COMPONENT', 1]],
        );

        $batchId = $this->postJson('/api/v1/products/import/bundle', ['file' => $file])
            ->assertStatus(202)
            ->json('data.id');
        $this->postJson("/api/v1/products/import/batches/{$batchId}/confirm")
            ->assertStatus(202);

        $this->assertSame($before, Product::count());
        $this->assertSame(1, Product::where('sku', 'EXISTING-BUNDLE')->whereNull('deleted_at')->count());
        $this->assertDatabaseHas('product_bundle_items', [
            'bundle_product_id' => $bundle->id,
            'component_variant_id' => $componentVariant->id,
            'qty' => 1,
        ]);
        $this->assertDatabaseHas('product_variants', [
            'product_id' => $bundle->id,
            'sku' => '__bundle__'.$bundle->id,
            'is_internal' => true,
        ]);
    }

    public function test_import_bundle_rejects_swapped_bundle_name_and_sku_columns(): void
    {
        $cat = \Modules\Product\Models\Category::firstOrCreate(['name' => 'General']);
        $component = Product::create([
            'name' => 'Komponen Tertukar',
            'category_id' => $cat->id,
            'is_bundle' => false,
            'status' => Product::STATUS_MASTER,
        ]);
        ProductVariant::create([
            'product_id' => $component->id,
            'sku' => 'COMPONENT-SWAP',
            'is_active' => true,
        ]);

        $file = $this->makeUpload(
            ['item_code', 'bundle_name', 'sku_composition', 'qty'],
            [['CASE + STANDING + PATCH 4 IPHONE 15', 'STANDING-PATCH-4-IP-15', 'COMPONENT-SWAP', 1]],
        );

        $batchId = $this->postJson('/api/v1/products/import/bundle', ['file' => $file])
            ->assertStatus(202)
            ->json('data.id');
        $batch = ProductImportBatch::findOrFail($batchId);

        $this->assertSame(0, $batch->success_rows);
        $this->assertSame(1, $batch->failed_rows);
        $this->assertDatabaseHas('product_import_rows', [
            'import_batch_id' => $batchId,
            'status' => 'invalid',
        ]);
        $this->assertStringContainsString(
            'Kolom import bundle tertukar',
            (string) ProductImportBatch::findOrFail($batchId)->rows()->firstOrFail()->message,
        );
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
        $this->assertSame(ProductImportBatch::STATE_PREVIEWED, $batch->state);
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
