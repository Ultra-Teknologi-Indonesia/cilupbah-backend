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
use Modules\Product\Repositories\ProductWriteRepository;
use Modules\Product\Services\ChannelMappingRepairService;
use Modules\Product\Services\ChannelSkuHealth;
use Modules\Product\Services\MasterProductMerger;
use Modules\Product\Services\MixedChannelMappingSplitService;
use Modules\Product\Services\StaleChannelMappingPruneService;
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

    public function test_repository_supersede_removes_channel_variant_mapping_before_soft_delete(): void
    {
        [, $variant, $mapping] = $this->listedProduct('SUPERSEDE-GUARD');
        $variantMapping = ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $mapping->id,
            'variant_id' => $variant->id,
            'external_sku_id' => 'SUPERSEDE-GUARD-EXT',
            'sync_enabled' => true,
        ]);

        app(ProductWriteRepository::class)->supersedeVariant((string) $variant->id);

        $this->assertSoftDeleted('product_variants', ['id' => $variant->id]);
        $this->assertDatabaseMissing('product_variant_channel_mappings', ['id' => $variantMapping->id]);
    }

    public function test_repository_free_inactive_skus_removes_channel_variant_mappings_before_soft_delete(): void
    {
        [$product, $variant, $mapping] = $this->listedProduct('FREE-SKU-GUARD');
        $variantMapping = ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $mapping->id,
            'variant_id' => $variant->id,
            'external_sku_id' => 'FREE-SKU-GUARD-EXT',
            'sync_enabled' => true,
        ]);
        $variant->update(['is_active' => false]);

        app(ProductWriteRepository::class)->freeInactiveVariantSkus((string) $product->id, [(string) $variant->sku]);

        $this->assertSoftDeleted('product_variants', ['id' => $variant->id]);
        $this->assertDatabaseMissing('product_variant_channel_mappings', ['id' => $variantMapping->id]);
    }

    public function test_stale_parent_mapping_is_pruned_only_after_revalidation_and_audited(): void
    {
        [$product, $variant, $mapping] = $this->listedProduct('PRUNE-PARENT-GUARD');
        $variantMapping = ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $mapping->id,
            'variant_id' => $variant->id,
            'external_sku_id' => 'PRUNE-PARENT-GUARD-EXT',
            'sync_enabled' => true,
        ]);

        DB::table('products')->where('id', $product->id)->update(['deleted_at' => now()]);

        $result = app(StaleChannelMappingPruneService::class)->prune((string) $mapping->id);

        $this->assertTrue($result['deleted_parent']);
        $this->assertSame(1, $result['deleted_children']);
        $this->assertDatabaseMissing('product_channel_mappings', ['id' => $mapping->id]);
        $this->assertDatabaseMissing('product_variant_channel_mappings', ['id' => $variantMapping->id]);
        $this->assertDatabaseHas('channel_mapping_prune_audits', [
            'mapping_id' => $mapping->id,
            'reason' => 'LISTING_MASTER_DELETED',
        ]);
    }

    public function test_stale_child_mapping_is_pruned_and_empty_parent_is_removed(): void
    {
        [$product, $variant, $mapping] = $this->listedProduct('PRUNE-CHILD-GUARD');
        $variantMapping = ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $mapping->id,
            'variant_id' => $variant->id,
            'external_sku_id' => 'PRUNE-CHILD-GUARD-EXT',
            'sync_enabled' => true,
        ]);

        DB::table('product_variants')->where('id', $variant->id)->update(['deleted_at' => now()]);

        $result = app(StaleChannelMappingPruneService::class)->prune((string) $mapping->id);

        $this->assertTrue($result['deleted_parent']);
        $this->assertSame(1, $result['deleted_children']);
        $this->assertDatabaseMissing('product_channel_mappings', ['id' => $mapping->id]);
        $this->assertDatabaseMissing('product_variant_channel_mappings', ['id' => $variantMapping->id]);
        $this->assertDatabaseHas('channel_mapping_prune_audits', [
            'mapping_id' => $mapping->id,
            'reason' => 'VARIANT_DELETED',
        ]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'deleted_at' => null]);
    }

    public function test_active_listing_without_channel_variant_rows_is_not_an_automatic_prune_candidate(): void
    {
        [, , $mapping] = $this->listedProduct('EMPTY-LISTING-REVIEW');

        $candidates = app(StaleChannelMappingPruneService::class)->candidates();

        $this->assertFalse($candidates->pluck('mapping_id')->contains((string) $mapping->id));

        $this->expectException(DomainException::class);

        app(StaleChannelMappingPruneService::class)->prune((string) $mapping->id);
    }

    public function test_stale_mapping_prune_command_is_dry_run_until_apply_is_explicitly_confirmed(): void
    {
        [$product, $variant, $mapping] = $this->listedProduct('PRUNE-COMMAND-GUARD');
        ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $mapping->id,
            'variant_id' => $variant->id,
            'external_sku_id' => 'PRUNE-COMMAND-GUARD-EXT',
            'sync_enabled' => true,
        ]);
        DB::table('products')->where('id', $product->id)->update(['deleted_at' => now()]);

        $this->artisan('products:prune-stale-channel-mappings', ['--limit' => 1])
            ->expectsOutputToContain('DRY-RUN')
            ->assertExitCode(0);

        $this->assertDatabaseHas('product_channel_mappings', ['id' => $mapping->id]);

        $this->artisan('products:prune-stale-channel-mappings', [
            '--limit' => 1,
            '--apply' => true,
            '--confirm' => 'PRUNE-STALE-CHANNEL-MAPPINGS',
        ])->assertExitCode(0);

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

    public function test_mixed_legacy_listing_is_split_without_moving_the_source_product_variants(): void
    {
        [$source, $stayingVariant, $sourceMapping] = $this->listedProduct('SPLIT-SOURCE');
        [$target, $movingVariant] = $this->productWithVariant('SPLIT-TARGET');

        ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $sourceMapping->id,
            'variant_id' => $stayingVariant->id,
            'external_sku_id' => 'SPLIT-STAYS-EXT',
            'sync_enabled' => true,
        ]);
        DB::table('product_variant_channel_mappings')->insert([
            'id' => (string) Uuid::uuid7(),
            'product_channel_mapping_id' => $sourceMapping->id,
            'variant_id' => $movingVariant->id,
            'external_sku_id' => 'SPLIT-MOVES-EXT',
            'sync_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(MixedChannelMappingSplitService::class);

        $plans = $service->candidates();

        $this->assertCount(1, $plans);
        $this->assertSame(1, $plans->first()->moved_models);
        $this->assertSame(1, $plans->first()->created_parents);

        $result = $service->split((string) $sourceMapping->id);

        $this->assertSame(1, $result['moved_models']);
        $this->assertSame(1, $result['created_parents']);
        $this->assertDatabaseHas('product_variant_channel_mappings', [
            'product_channel_mapping_id' => $sourceMapping->id,
            'variant_id' => $stayingVariant->id,
        ]);

        $targetMappingId = ProductChannelMapping::query()
            ->where('channel_shop_id', $sourceMapping->channel_shop_id)
            ->where('external_product_id', $sourceMapping->external_product_id)
            ->where('product_id', $target->id)
            ->value('id');

        $this->assertNotNull($targetMappingId);
        $this->assertDatabaseHas('product_variant_channel_mappings', [
            'product_channel_mapping_id' => $targetMappingId,
            'variant_id' => $movingVariant->id,
        ]);
        $this->assertDatabaseHas('channel_mapping_split_audits', [
            'source_mapping_id' => $sourceMapping->id,
            'target_mapping_id' => $targetMappingId,
            'target_product_id' => $target->id,
            'moved_models' => 1,
            'created_target_mapping' => true,
        ]);
    }

    public function test_mixed_mapping_split_command_requires_explicit_apply_confirmation(): void
    {
        [$source, $stayingVariant, $sourceMapping] = $this->listedProduct('SPLIT-COMMAND-SOURCE');
        [, $movingVariant] = $this->productWithVariant('SPLIT-COMMAND-TARGET');

        ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $sourceMapping->id,
            'variant_id' => $stayingVariant->id,
            'external_sku_id' => 'SPLIT-COMMAND-STAYS-EXT',
            'sync_enabled' => true,
        ]);
        DB::table('product_variant_channel_mappings')->insert([
            'id' => (string) Uuid::uuid7(),
            'product_channel_mapping_id' => $sourceMapping->id,
            'variant_id' => $movingVariant->id,
            'external_sku_id' => 'SPLIT-COMMAND-MOVES-EXT',
            'sync_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('products:split-mixed-channel-mappings', ['--limit' => 25])
            ->expectsOutputToContain('DRY-RUN')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('product_channel_mappings', [
            'product_id' => $movingVariant->product_id,
            'external_product_id' => $sourceMapping->external_product_id,
        ]);

        $this->artisan('products:split-mixed-channel-mappings', [
            '--limit' => 25,
            '--apply' => true,
            '--confirm' => 'SPLIT-MIXED-CHANNEL-MAPPINGS',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('product_channel_mappings', [
            'product_id' => $movingVariant->product_id,
            'external_product_id' => $sourceMapping->external_product_id,
        ]);
    }

    public function test_mixed_mapping_with_a_stale_child_is_not_eligible_for_automatic_split(): void
    {
        [$source, $stayingVariant, $sourceMapping] = $this->listedProduct('SPLIT-STALE-SOURCE');
        [, $movingVariant] = $this->productWithVariant('SPLIT-STALE-TARGET');
        $staleVariant = ProductVariant::create([
            'product_id' => $source->id,
            'sku' => 'SPLIT-STALE-CHILD',
            'is_active' => true,
        ]);

        ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $sourceMapping->id,
            'variant_id' => $stayingVariant->id,
            'external_sku_id' => 'SPLIT-STALE-STAYS-EXT',
            'sync_enabled' => true,
        ]);
        DB::table('product_variant_channel_mappings')->insert([
            [
                'id' => (string) Uuid::uuid7(),
                'product_channel_mapping_id' => $sourceMapping->id,
                'variant_id' => $movingVariant->id,
                'external_sku_id' => 'SPLIT-STALE-MOVES-EXT',
                'sync_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Uuid::uuid7(),
                'product_channel_mapping_id' => $sourceMapping->id,
                'variant_id' => $staleVariant->id,
                'external_sku_id' => 'SPLIT-STALE-CHILD-EXT',
                'sync_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('product_variants')->where('id', $staleVariant->id)->update(['deleted_at' => now()]);

        $this->assertCount(0, app(MixedChannelMappingSplitService::class)->candidates());
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
