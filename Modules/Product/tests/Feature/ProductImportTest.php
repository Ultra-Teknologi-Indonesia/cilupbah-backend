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

    /** Buat file xlsx nyata dari headings+rows lalu bungkus sebagai UploadedFile. */
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
        $headings = ['item_group_name', 'item_code', 'sell_price', 'category', 'brand'];
        $rows = [
            ['Kaos Polos', 'KP-HITAM-M', 75000, 'Fashion', 'Cilupbah'],   // valid
            ['Kaos Polos', 'KP-PUTIH-L', 80000, 'Fashion', 'Cilupbah'],   // valid (varian ke-2)
            ['Celana', '', 50000, 'Fashion', 'Cilupbah'],                 // invalid: item_code kosong
            ['Topi', 'TP-1', 'bukan-angka', 'Fashion', 'Cilupbah'],       // invalid: sell_price non-numerik
        ];

        $file = $this->makeUpload($headings, $rows);

        $response = $this->postJson('/api/v1/products/import/single', ['file' => $file]);

        $response->assertStatus(202);
        $batchId = $response->json('data.id');
        $this->assertNotNull($batchId);

        // Queue sync → job sudah jalan. Ambil state final dari DB.
        $batch = ProductImportBatch::find($batchId);
        $this->assertSame(ProductImportBatch::STATE_DONE_WITH_ERRORS, $batch->state);
        $this->assertSame(4, $batch->total_rows);
        $this->assertSame(2, $batch->success_rows);
        $this->assertSame(2, $batch->failed_rows);

        // Produk valid masuk, berstatus master.
        $this->assertDatabaseHas('products', ['name' => 'Kaos Polos', 'status' => Product::STATUS_MASTER]);
        $this->assertDatabaseHas('product_variants', ['sku' => 'KP-HITAM-M']);
        $this->assertDatabaseHas('product_variants', ['sku' => 'KP-PUTIH-L']);
        // Dua baris nama sama => satu produk.
        $this->assertSame(1, Product::where('name', 'Kaos Polos')->count());

        // Error tercatat dengan baris & kolom.
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

        $bundle = Product::create(['name' => 'Paket Hemat', 'category_id' => 1, 'status' => Product::STATUS_MASTER, 'is_active' => true]);
        $bundleVariant = ProductVariant::create(['product_id' => $bundle->id, 'sku' => 'BUNDLE-A', 'sell_price' => 100000, 'is_active' => true]);

        $comp = Product::create(['name' => 'Komponen', 'category_id' => 1, 'status' => Product::STATUS_MASTER, 'is_active' => true]);
        $compVariant = ProductVariant::create(['product_id' => $comp->id, 'sku' => 'COMP-1', 'sell_price' => 40000, 'is_active' => true]);

        $file = $this->makeUpload(
            ['item_code', 'sku_composition', 'qty'],
            [
                ['BUNDLE-A', 'COMP-1', 2],    // valid
                ['BUNDLE-A', 'NOPE', 1],      // invalid: komponen tidak ada
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
