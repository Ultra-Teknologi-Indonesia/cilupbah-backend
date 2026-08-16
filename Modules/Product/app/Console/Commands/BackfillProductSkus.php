<?php

namespace Modules\Product\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;

class BackfillProductSkus extends Command
{
    protected $signature = 'products:backfill-skus {--dry-run : Only show what would be updated without modifying database}';

    protected $description = 'Backfill missing product.sku from variants and import batch rows';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $this->info($isDryRun ? '🔍 Running in DRY-RUN mode (no changes will be made)...' : '🚀 Starting Bundle SKU backfill...');

        $products = Product::where('is_bundle', true)
            ->where(function ($q) {
                $q->whereNull('sku')->orWhereRaw("TRIM(sku) = ''");
            })
            ->get(['id', 'name', 'sku', 'is_bundle', 'created_at']);

        if ($products->isEmpty()) {
            $this->info('✅ All bundle products already have valid SKUs. Nothing to backfill.');
            return self::SUCCESS;
        }

        $this->line("Found {$products->count()} bundle product(s) with missing SKU.");

        $updatedCount = 0;
        $rows = [];

        foreach ($products as $product) {
            $resolvedSku = null;
            $source = null;

            $variant = ProductVariant::where('product_id', $product->id)
                ->whereNotNull('sku')
                ->whereRaw("TRIM(sku) != ''")
                ->whereNull('deleted_at')
                ->orderBy('sequence_item')
                ->orderBy('created_at')
                ->first(['id', 'sku']);

            if ($variant && ! empty(trim((string) $variant->sku))) {
                $resolvedSku = trim((string) $variant->sku);
                $source = 'product_variants';
            }

            if (! $resolvedSku) {
                $importRow = DB::table('product_import_rows')
                    ->where('name', $product->name)
                    ->whereNotNull('sku')
                    ->whereRaw("TRIM(sku) != ''")
                    ->orderBy('created_at', 'desc')
                    ->first(['sku']);

                if ($importRow && ! empty(trim((string) $importRow->sku))) {
                    $resolvedSku = trim((string) $importRow->sku);
                    $source = 'product_import_rows (name)';
                }
            }

            if (! $resolvedSku) {
                $importRowPayload = DB::table('product_import_rows')
                    ->whereRaw("payload->>'bundle_name' = ?", [$product->name])
                    ->orWhereRaw("payload->>'item_group_name' = ?", [$product->name])
                    ->orderBy('created_at', 'desc')
                    ->first(['sku', 'payload']);

                if ($importRowPayload) {
                    $payload = json_decode((string) $importRowPayload->payload, true);
                    $skuFromPayload = $payload['item_code'] ?? $importRowPayload->sku ?? null;
                    if ($skuFromPayload && ! empty(trim((string) $skuFromPayload))) {
                        $resolvedSku = trim((string) $skuFromPayload);
                        $source = 'product_import_rows (payload)';
                    }
                }
            }

            if ($resolvedSku) {
                $rows[] = [
                    'ID' => $product->id,
                    'Type' => $product->is_bundle ? 'Bundle' : 'Single/Variant',
                    'Name' => mb_strimwidth($product->name, 0, 40, '...'),
                    'Assigned SKU' => $resolvedSku,
                    'Source' => $source,
                ];

                if (! $isDryRun) {
                    Product::where('id', $product->id)->update(['sku' => $resolvedSku]);
                }

                $updatedCount++;
            }
        }

        if (! empty($rows)) {
            $this->table(['ID', 'Type', 'Name', 'Assigned SKU', 'Source'], $rows);
        }

        if ($isDryRun) {
            $this->info("🔍 [DRY-RUN] Found {$updatedCount} product(s) that can be backfilled.");
        } else {
            $this->info("✅ Successfully backfilled {$updatedCount} product SKU(s).");
        }

        return self::SUCCESS;
    }
}
