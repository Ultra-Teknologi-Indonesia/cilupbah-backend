<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductMerge;
use Modules\Product\Models\ProductMergeHidden;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Services\ProductMergeService;
use Modules\Product\Support\SkuGrouping;
use Tests\TestCase;

class ProductMergeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Aksesoris']);
    }

    private function service(): ProductMergeService
    {
        return app(ProductMergeService::class);
    }

    private function makeProduct(string $name, array $skus = [], string $status = Product::STATUS_MASTER): Product
    {
        $p = Product::create([
            'name' => $name,
            'category_id' => 1,
            'status' => $status,
            'is_active' => true,
        ]);
        foreach ($skus as $sku) {
            ProductVariant::create([
                'product_id' => $p->id,
                'sku' => $sku,
                'sell_price' => 10000,
                'is_active' => true,
            ]);
        }

        return $p;
    }

    public function test_sku_type_code_handles_all_cases(): void
    {
        $this->assertSame('SLR', SkuGrouping::skuTypeCode('SLR-GREEN-IP14'));
        $this->assertSame('SLR', SkuGrouping::skuTypeCode('slr-green'));
        $this->assertSame('ABCD', SkuGrouping::skuTypeCode('ABCD'));
        $this->assertSame('AB', SkuGrouping::skuTypeCode('AB'));
        $this->assertNull(SkuGrouping::skuTypeCode('A-B'));     
        $this->assertNull(SkuGrouping::skuTypeCode(''));
        $this->assertNull(SkuGrouping::skuTypeCode('-XYZ'));    
        $this->assertNull(SkuGrouping::skuTypeCode(null));
    }

    public function test_normalize_name_strips_diacritics_case_and_whitespace(): void
    {
        $this->assertSame('cafe latte', SkuGrouping::normalizeName('  Café   Latte '));
        $this->assertSame('soft case', SkuGrouping::normalizeName('SOFT  case'));
    }

    public function test_normalize_name_preserves_non_latin_characters(): void
    {

        $this->assertSame('iphone 手机壳 hitam', SkuGrouping::normalizeName('iPhone 手机壳 Hitam'));
        $this->assertSame('手机壳 a', SkuGrouping::normalizeName('手机壳 A'));
        $this->assertSame('手机膜 b', SkuGrouping::normalizeName('手机膜 B'));

        $this->assertNotSame(
            SkuGrouping::normalizeName('手机壳 A'),
            SkuGrouping::normalizeName('手机膜 B'),
        );
    }

    public function test_name_signature_strips_device_tail(): void
    {
        $this->assertSame('soft case premium', SkuGrouping::nameSignature('Soft Case Premium for iPhone 14 Pro'));
        $this->assertSame('matte soft case', SkuGrouping::nameSignature('Matte Soft Case (Slim) / Bonus'));
    }

    public function test_auto_merge_groups_by_sku_prefix(): void
    {
        $this->makeProduct('Soft Case Merah Panjang', ['SLR-RED']);
        $this->makeProduct('Soft Case Biru', ['SLR-BLUE']);
        $this->makeProduct('Produk Lain Sendiri', ['XYZ-1']); 

        $result = $this->service()->autoMergeAll();

        $this->assertSame(2, $result['merged']);
        $this->assertSame(2, ProductMerge::count());

        $masters = ProductMerge::pluck('master_name')->unique()->values();
        $this->assertCount(1, $masters);
        $this->assertSame('Soft Case Merah Panjang', $masters[0]);
    }

    public function test_auto_merge_skips_singletons(): void
    {
        $this->makeProduct('Solo A', ['AAA-1']);
        $this->makeProduct('Solo B', ['BBB-1']);

        $result = $this->service()->autoMergeAll();

        $this->assertSame(0, $result['merged']);
        $this->assertSame(0, ProductMerge::count());
    }

    public function test_auto_merge_is_idempotent(): void
    {
        $this->makeProduct('Case Satu', ['SLR-1']);
        $this->makeProduct('Case Dua', ['SLR-2']);

        $first = $this->service()->autoMergeAll();
        $second = $this->service()->autoMergeAll();

        $this->assertSame(2, $first['merged']);
        $this->assertSame(0, $second['merged']);
        $this->assertSame(2, ProductMerge::count());
    }

    public function test_auto_merge_respects_existing_master(): void
    {
        $p1 = $this->makeProduct('Case Satu', ['SLR-1']);
        $p2 = $this->makeProduct('Case Dua', ['SLR-2']);

        ProductMerge::create(['product_id' => $p1->id, 'master_name' => 'Master Custom']);

        $this->service()->autoMergeAll();

        $this->assertSame('Master Custom', ProductMerge::where('product_id', $p2->id)->value('master_name'));
    }

    public function test_auto_merge_uses_name_pattern_group_across_different_prefixes(): void
    {

        $a = $this->makeProduct('Premium Full Cover Hitam', ['AAA-1']);
        $b = $this->makeProduct('Premium Full Cover Putih', ['BBB-1']);

        $this->service()->autoMergeAll();

        $ma = ProductMerge::where('product_id', $a->id)->value('master_name');
        $mb = ProductMerge::where('product_id', $b->id)->value('master_name');
        $this->assertNotNull($ma);
        $this->assertSame($ma, $mb); 
    }

    public function test_auto_merge_skips_products_without_sku(): void
    {
        $this->makeProduct('Tanpa SKU Sama Sekali', []); 

        $result = $this->service()->autoMergeAll();

        $this->assertSame(0, $result['merged']);
    }

    public function test_apply_merge_rejects_missing_product(): void
    {
        $p = $this->makeProduct('Ada', ['AAA-1']);

        $this->expectException(\DomainException::class);
        $this->service()->applyMerge('Master', [$p->id, 'ffffffff-ffff-7fff-8fff-ffffffffffff']);
    }

    public function test_apply_merge_moves_existing_merge(): void
    {
        $a = $this->makeProduct('A', ['AAA-1']);
        $b = $this->makeProduct('B', ['BBB-1']);
        ProductMerge::create(['product_id' => $a->id, 'master_name' => 'Old Master']);

        $this->service()->applyMerge('New Master', [$a->id, $b->id]);

        $this->assertSame('New Master', ProductMerge::where('product_id', $a->id)->value('master_name'));
        $this->assertSame('New Master', ProductMerge::where('product_id', $b->id)->value('master_name'));
        $this->assertSame(2, ProductMerge::count());
    }

    public function test_cascade_hidden_on_merge_inherits_to_new_master(): void
    {
        $a = $this->makeProduct('Produk A Hidden', ['AAA-1']);
        $b = $this->makeProduct('Produk B', ['BBB-1']);

        $this->service()->bulkHide(['Produk A Hidden']);

        $this->service()->applyMerge('Master Gabungan', [$a->id, $b->id]);

        $this->assertTrue(ProductMergeHidden::where('master_name', 'Master Gabungan')->exists());
        $this->assertFalse(ProductMergeHidden::where('master_name', 'Produk A Hidden')->exists());
    }

    public function test_cascade_hidden_on_unmerge_pushes_down_to_product_names(): void
    {
        $a = $this->makeProduct('Produk A', ['AAA-1']);
        $b = $this->makeProduct('Produk B', ['BBB-1']);
        $this->service()->applyMerge('Master Gabungan', [$a->id, $b->id]);
        $this->service()->bulkHide(['Master Gabungan']);

        $this->service()->unmergeMaster('Master Gabungan');

        $this->assertFalse(ProductMergeHidden::where('master_name', 'Master Gabungan')->exists());
        $this->assertTrue(ProductMergeHidden::where('master_name', 'Produk A')->exists());
        $this->assertTrue(ProductMergeHidden::where('master_name', 'Produk B')->exists());
    }

    public function test_catalog_groups_and_counts(): void
    {
        $a = $this->makeProduct('Case Satu', ['SLR-1']);
        $b = $this->makeProduct('Case Dua', ['SLR-2']);
        $this->makeProduct('Solo Sendiri', ['ZZZ-1']);
        $this->service()->applyMerge('Master SLR', [$a->id, $b->id]);

        $catalog = $this->service()->catalog('all');

        $this->assertSame(2, $catalog['counts']['all']);
        $this->assertSame(1, $catalog['counts']['merged']);
        $this->assertSame(1, $catalog['counts']['unmerged']);

        $merged = $this->service()->catalog('merged');
        $this->assertSame('Master SLR', $merged['rows'][0]['name']);
        $this->assertSame(2, $merged['rows'][0]['product_count']);
    }
}
