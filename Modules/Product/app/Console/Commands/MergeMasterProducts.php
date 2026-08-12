<?php

namespace Modules\Product\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MergeMasterProducts extends Command
{
    protected $signature = 'products:merge-masters
                            {target : UUID master tujuan}
                            {source* : UUID master yang dilebur ke tujuan}
                            {--apply : Jalankan perubahan (tanpa ini hanya pratinjau)}';

    protected $description = 'Gabungkan master produk beserta varian dan mapping channel-nya';

    public function handle(): int
    {
        $targetId = (string) $this->argument('target');
        $sourceIds = array_values(array_unique(array_map('strval', $this->argument('source'))));
        $apply = (bool) $this->option('apply');

        if (in_array($targetId, $sourceIds, true)) {
            $this->error('Master tujuan tidak boleh ikut jadi sumber.');

            return self::FAILURE;
        }

        $target = DB::table('products')->where('id', $targetId)->first();

        if (! $target) {
            $this->error("Master tujuan {$targetId} tidak ditemukan.");

            return self::FAILURE;
        }

        foreach ($sourceIds as $sourceId) {
            if (! DB::table('products')->where('id', $sourceId)->exists()) {
                $this->error("Master sumber {$sourceId} tidak ditemukan.");

                return self::FAILURE;
            }
        }

        $this->line("Tujuan : {$target->name}");
        $this->line('Varian tujuan saat ini: ' . $this->variantCount($targetId));
        $this->newLine();

        $plan = [];

        foreach ($sourceIds as $sourceId) {
            $plan[$sourceId] = $this->planFor($targetId, $sourceId);
        }

        $this->printPlan($plan);

        $collisions = collect($plan)->flatMap(fn ($p) => $p['sku_bentrok'])->unique()->values();

        if ($collisions->isNotEmpty()) {
            $this->error('Ada SKU yang sama di tujuan dan sumber — gabungkan manual dulu: ' . $collisions->implode(', '));

            return self::FAILURE;
        }

        if (! $apply) {
            $this->newLine();
            $this->warn('PRATINJAU — tidak ada yang ditulis. Tambahkan --apply untuk menjalankan.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($targetId, $sourceIds) {
            foreach ($sourceIds as $sourceId) {
                $this->mergeOne($targetId, $sourceId);
            }
        });

        $this->newLine();
        $this->info('Selesai. Varian tujuan sekarang: ' . $this->variantCount($targetId));
        $this->line('Jalankan download ulang listing terkait agar mapping tersegarkan.');

        return self::SUCCESS;
    }

    private function variantCount(string $productId): int
    {
        return DB::table('product_variants')
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->count();
    }

    private function planFor(string $targetId, string $sourceId): array
    {
        $sourceSkus = DB::table('product_variants')
            ->where('product_id', $sourceId)
            ->whereNull('deleted_at')
            ->pluck('sku')
            ->filter()
            ->all();

        $targetSkus = DB::table('product_variants')
            ->where('product_id', $targetId)
            ->whereNull('deleted_at')
            ->pluck('sku')
            ->filter()
            ->all();

        $sourceListings = DB::table('product_channel_mappings')
            ->where('product_id', $sourceId)
            ->get(['id', 'channel_shop_id', 'external_product_id']);

        $targetListings = DB::table('product_channel_mappings')
            ->where('product_id', $targetId)
            ->get(['id', 'channel_shop_id', 'external_product_id']);

        $targetKeys = $targetListings
            ->mapWithKeys(fn ($m) => [$m->channel_shop_id . '|' . (string) $m->external_product_id => $m->id])
            ->all();

        $dilebur = 0;
        $dipindah = 0;

        foreach ($sourceListings as $listing) {
            $key = $listing->channel_shop_id . '|' . (string) $listing->external_product_id;
            isset($targetKeys[$key]) ? $dilebur++ : $dipindah++;
        }

        return [
            'nama' => DB::table('products')->where('id', $sourceId)->value('name'),
            'varian_pindah' => $this->variantCount($sourceId),
            'sku_bentrok' => array_values(array_intersect($sourceSkus, $targetSkus)),
            'listing_dilebur' => $dilebur,
            'listing_dipindah' => $dipindah,
        ];
    }

    private function printPlan(array $plan): void
    {
        $this->table(
            ['Master sumber', 'Varian pindah', 'Listing dilebur', 'Listing dipindah', 'SKU bentrok'],
            collect($plan)->map(fn ($p) => [
                mb_strimwidth((string) $p['nama'], 0, 40, '…'),
                $p['varian_pindah'],
                $p['listing_dilebur'],
                $p['listing_dipindah'],
                count($p['sku_bentrok']),
            ])->values()->all()
        );
    }

    private function mergeOne(string $targetId, string $sourceId): void
    {
        $this->syncVariationTypes($targetId, $sourceId);

        $variantIds = DB::table('product_variants')
            ->where('product_id', $sourceId)
            ->pluck('id')
            ->all();

        if ($variantIds) {
            DB::table('product_media')
                ->whereIn('variant_id', $variantIds)
                ->update(['product_id' => $targetId, 'updated_at' => now()]);
        }

        DB::table('product_variants')
            ->where('product_id', $sourceId)
            ->update(['product_id' => $targetId, 'updated_at' => now()]);

        $this->moveChannelMappings($targetId, $sourceId);

        DB::table('product_variation_types')->where('product_id', $sourceId)->delete();
        DB::table('product_specifications')->where('product_id', $sourceId)->delete();
        DB::table('product_media')->where('product_id', $sourceId)->delete();
        DB::table('products')->where('id', $sourceId)->delete();
    }

    private function syncVariationTypes(string $targetId, string $sourceId): void
    {
        $targetAttrs = DB::table('product_variation_types')
            ->where('product_id', $targetId)
            ->pluck('attribute_id')
            ->all();

        $nextSort = (int) DB::table('product_variation_types')
            ->where('product_id', $targetId)
            ->max('sort_order');

        $sourceTypes = DB::table('product_variation_types')
            ->where('product_id', $sourceId)
            ->orderBy('sort_order')
            ->get(['attribute_id']);

        foreach ($sourceTypes as $type) {
            if (in_array($type->attribute_id, $targetAttrs, false)) {
                continue;
            }

            $nextSort++;
            DB::table('product_variation_types')->insert([
                'product_id' => $targetId,
                'attribute_id' => $type->attribute_id,
                'sort_order' => $nextSort,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $targetAttrs[] = $type->attribute_id;
        }
    }

    private function moveChannelMappings(string $targetId, string $sourceId): void
    {
        $targetKeys = DB::table('product_channel_mappings')
            ->where('product_id', $targetId)
            ->get(['id', 'channel_shop_id', 'external_product_id'])
            ->mapWithKeys(fn ($m) => [$m->channel_shop_id . '|' . (string) $m->external_product_id => $m->id])
            ->all();

        $sourceMappings = DB::table('product_channel_mappings')
            ->where('product_id', $sourceId)
            ->get(['id', 'channel_shop_id', 'external_product_id']);

        foreach ($sourceMappings as $mapping) {
            $key = $mapping->channel_shop_id . '|' . (string) $mapping->external_product_id;
            $existingId = $targetKeys[$key] ?? null;

            if ($existingId === null) {
                DB::table('product_channel_mappings')
                    ->where('id', $mapping->id)
                    ->update(['product_id' => $targetId, 'updated_at' => now()]);

                $targetKeys[$key] = $mapping->id;

                continue;
            }

            $sudahAda = DB::table('product_variant_channel_mappings')
                ->where('product_channel_mapping_id', $existingId)
                ->whereNotNull('external_sku_id')
                ->pluck('external_sku_id')
                ->all();

            DB::table('product_variant_channel_mappings')
                ->where('product_channel_mapping_id', $mapping->id)
                ->whereNotIn('external_sku_id', $sudahAda ?: [''])
                ->update(['product_channel_mapping_id' => $existingId, 'updated_at' => now()]);

            DB::table('product_variant_channel_mappings')
                ->where('product_channel_mapping_id', $mapping->id)
                ->delete();

            DB::table('product_channel_mappings')->where('id', $mapping->id)->delete();
        }
    }
}
