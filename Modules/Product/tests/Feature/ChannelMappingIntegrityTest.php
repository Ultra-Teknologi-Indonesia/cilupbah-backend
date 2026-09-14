<?php

declare(strict_types=1);

namespace Modules\Product\Tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Jobs\SyncStockToChannelsJob;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;
use Modules\Product\Repositories\ProductRepository;
use Modules\Product\Services\ChannelMappingRepairService;
use Modules\Product\Services\ChannelSkuHealth;
use Modules\Product\Services\MasterProductMerger;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class ChannelMappingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create(['name' => 'Integrity category']);
    }

    public function test_direct_product_soft_delete_removes_all_channel_mappings(): void
    {
        [$product, $variant, $mapping] = $this->listedProduct('DELETE-GUARD');
        $variantMapping = ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $mapping->id,
            'variant_id' => $variant->id,
            'external_sku_id' => 'DELETE-GUARD-EXT',
            'sync_enabled' => true,
        ]);

        $product->delete();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('product_variant_channel_mappings', ['id' => $variantMapping->id]);
        $this->assertDatabaseMissing('product_channel_mappings', ['id' => $mapping->id]);
    }

    public function test_mapping_rejects_variant_owned_by_another_product(): void
    {
        [, , $mapping] = $this->listedProduct('OWNER-A');
        [, $foreignVariant] = $this->listedProduct('OWNER-B');

        $this->expectException(DomainException::class);

        ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $mapping->id,
            'variant_id' => $foreignVariant->id,
            'external_sku_id' => 'OWNER-B-EXT',
            'sync_enabled' => true,
        ]);
    }

    public function test_stale_legacy_mapping_is_never_dispatched_for_stock_sync(): void
    {
        [$product, $activeVariant, $mapping] = $this->listedProduct('STOCK-GUARD');
        $staleVariant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'STOCK-GUARD-STALE',
            'is_active' => true,
        ]);

        DB::table('product_variants')->where('id', $staleVariant->id)->update(['deleted_at' => now()]);
        DB::table('product_variant_channel_mappings')->insert([
            'id' => (string) Uuid::uuid7(),
            'product_channel_mapping_id' => $mapping->id,
            'variant_id' => $staleVariant->id,
            'external_sku_id' => 'STALE-EXT',
            'sync_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Queue::fake();

        (new SyncStockToChannelsJob((string) $activeVariant->id))
            ->handle(app(ProductRepository::class));

        Queue::assertNotPushed(SyncProductToChannelJob::class);
    }

    public function test_health_check_detects_legacy_orphan_mapping(): void
    {
        [$product, , $mapping] = $this->listedProduct('AUDIT-GUARD');
        [, $foreignVariant] = $this->listedProduct('AUDIT-FOREIGN');

        DB::table('product_variant_channel_mappings')->insert([
            'id' => (string) Uuid::uuid7(),
            'product_channel_mapping_id' => $mapping->id,
            'variant_id' => $foreignVariant->id,
            'external_sku_id' => 'AUDIT-FOREIGN-EXT',
            'sync_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, app(ChannelSkuHealth::class)->orphanedChannelVariantMappings());
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_merging_a_variant_reassigns_every_fully_compatible_listing_parent(): void
    {
        [$oldProduct, $movingVariant, $mapping] = $this->listedProduct('MOVE-SAFE');
        $target = $this->productWithVariant('MOVE-TARGET');

        ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $mapping->id,
            'variant_id' => $movingVariant->id,
            'external_sku_id' => 'MOVE-SAFE-EXT',
            'sync_enabled' => true,
        ]);

        $moved = app(MasterProductMerger::class)->moveVariants((string) $target[0]->id, [(string) $movingVariant->id]);

        $this->assertSame(1, $moved);
        $this->assertDatabaseHas('product_variants', [
            'id' => $movingVariant->id,
            'product_id' => $target[0]->id,
        ]);
        $this->assertDatabaseHas('product_channel_mappings', [
            'id' => $mapping->id,
            'product_id' => $target[0]->id,
        ]);
        $this->assertDatabaseMissing('product_channel_mappings', [
            'id' => $mapping->id,
            'product_id' => $oldProduct->id,
        ]);
    }

    public function test_merging_a_variant_is_rejected_when_it_would_split_an_existing_listing(): void
    {
        [$oldProduct, $movingVariant, $mapping] = $this->listedProduct('MOVE-BLOCKED');
        $stayingVariant = ProductVariant::create([
            'product_id' => $oldProduct->id,
            'sku' => 'MOVE-STAYS',
            'is_active' => true,
        ]);
        [$target] = $this->productWithVariant('MOVE-OTHER-MASTER');

        ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $mapping->id,
            'variant_id' => $movingVariant->id,
            'external_sku_id' => 'MOVE-BLOCKED-EXT',
            'sync_enabled' => true,
        ]);
        ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $mapping->id,
            'variant_id' => $stayingVariant->id,
            'external_sku_id' => 'MOVE-STAYS-EXT',
            'sync_enabled' => true,
        ]);

        try {
            app(MasterProductMerger::class)->moveVariants((string) $target->id, [(string) $movingVariant->id]);
            $this->fail('Expected a split marketplace listing to block the merge.');
        } catch (DomainException) {
            $this->assertDatabaseHas('product_variants', [
                'id' => $movingVariant->id,
                'product_id' => $oldProduct->id,
            ]);
            $this->assertDatabaseHas('product_channel_mappings', [
                'id' => $mapping->id,
                'product_id' => $oldProduct->id,
            ]);
        }
    }

    public function test_legacy_fully_compatible_listing_can_be_repaired_with_a_revalidated_transaction(): void
    {
        [, , $mapping] = $this->listedProduct('REPAIR-OLD-PARENT');
        [$target, $targetVariant] = $this->productWithVariant('REPAIR-TARGET');

        DB::table('product_variant_channel_mappings')->insert([
            'id' => (string) Uuid::uuid7(),
            'product_channel_mapping_id' => $mapping->id,
            'variant_id' => $targetVariant->id,
            'external_sku_id' => 'REPAIR-TARGET-EXT',
            'sync_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $repair = app(ChannelMappingRepairService::class);
        $candidates = $repair->safeParentReassignments();

        $this->assertCount(1, $candidates);
        $this->assertSame((string) $mapping->id, (string) $candidates->first()->mapping_id);

        $result = $repair->reassign((string) $mapping->id);

        $this->assertSame((string) $target->id, $result['target_product_id']);
        $this->assertDatabaseHas('product_channel_mappings', [
            'id' => $mapping->id,
            'product_id' => $target->id,
        ]);
        $this->assertDatabaseHas('channel_mapping_repair_audits', [
            'mapping_id' => $mapping->id,
            'old_product_id' => $mapping->product_id,
            'new_product_id' => $target->id,
            'models' => 1,
        ]);
    }

    public function test_legacy_repair_command_is_dry_run_until_apply_is_explicitly_confirmed(): void
    {
        [$old, , $mapping] = $this->listedProduct('REPAIR-DRY-RUN');
        [$target, $targetVariant] = $this->productWithVariant('REPAIR-DRY-RUN-TARGET');

        DB::table('product_variant_channel_mappings')->insert([
            'id' => (string) Uuid::uuid7(),
            'product_channel_mapping_id' => $mapping->id,
            'variant_id' => $targetVariant->id,
            'external_sku_id' => 'REPAIR-DRY-RUN-EXT',
            'sync_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('products:repair-channel-mapping-integrity', ['--limit' => 25])
            ->expectsOutputToContain('DRY-RUN')
            ->assertExitCode(0);

        $this->assertDatabaseHas('product_channel_mappings', [
            'id' => $mapping->id,
            'product_id' => $old->id,
        ]);

        $this->artisan('products:repair-channel-mapping-integrity', [
            '--limit' => 25,
            '--apply' => true,
            '--confirm' => 'REASSIGN-CHANNEL-LISTINGS',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('product_channel_mappings', [
            'id' => $mapping->id,
            'product_id' => $target->id,
        ]);
    }

    private function listedProduct(string $sku): array
    {
        $channel = Channel::firstOrCreate(
            ['code' => 'shopee'],
            ['name' => 'Shopee', 'is_active' => true],
        );
        $shop = ChannelShop::firstOrCreate(
            ['shop_id' => 'INTEGRITY-SHOP'],
            ['channel_id' => $channel->id, 'shop_name' => 'Integrity Shop', 'is_active' => true],
        );
        $product = Product::create([
            'name' => $sku.' product',
            'category_id' => $this->category->id,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'is_active' => true,
        ]);
        $mapping = ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $shop->id,
            'external_product_id' => 'LISTING-'.$sku,
            'sync_status' => ProductChannelMapping::STATUS_SYNCED,
        ]);

        return [$product, $variant, $mapping];
    }

    private function productWithVariant(string $sku): array
    {
        $product = Product::create([
            'name' => $sku.' product',
            'category_id' => $this->category->id,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'is_active' => true,
        ]);

        return [$product, $variant];
    }
}
