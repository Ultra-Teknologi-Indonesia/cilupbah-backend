<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductSyncLog;

class RepairStaleSyncErrors extends Command
{
    protected $signature = 'channel:repair-stale-sync-errors
        {--apply : Terapkan perubahan (tanpa ini hanya dry-run)}
        {--limit=0 : Batasi jumlah mapping yang dipindai; gunakan 0 untuk tanpa batas}';

    protected $description = 'Perbaiki pesan error mapping channel yang tertimpa pesan retry generik';

    private const CHUNK_SIZE = 500;

    private const HISTORICAL_FALLBACK =
        'Sinkronisasi ke channel gagal setelah beberapa percobaan. Detail error historis tidak tersimpan; silakan sinkronisasi ulang.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $rawLimit = (string) $this->option('limit');

        if (! ctype_digit($rawLimit)) {
            $this->error('--limit harus berupa 0 atau bilangan bulat positif.');

            return self::FAILURE;
        }

        $limit = (int) $rawLimit;

        $query = ProductChannelMapping::query()
            ->where('sync_status', ProductChannelMapping::STATUS_FAILED)
            ->where(function ($query) {
                $query
                    ->whereRaw("LOWER(COALESCE(error_message, '')) LIKE ?", ['%too many%attempt%'])
                    ->orWhereRaw("LOWER(COALESCE(error_message, '')) LIKE ?", ['%maxattemptsexceeded%'])
                    ->orWhereRaw("LOWER(COALESCE(error_message, '')) LIKE ?", ['%attempted too many times%']);
            })
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $scanned = 0;
        $eligible = 0;
        $updated = 0;
        $unresolved = 0;
        $samples = [];

        $query->chunkById(self::CHUNK_SIZE, function (Collection $mappings) use (
            $apply,
            &$scanned,
            &$eligible,
            &$updated,
            &$unresolved,
            &$samples,
        ): void {
            $logs = $this->latestActionableLogs($mappings);

            foreach ($mappings as $mapping) {
                $scanned++;
                $eligible++;

                $key = $this->key($mapping->product_id, $mapping->channel_shop_id);
                $newMessage = $logs[$key] ?? self::HISTORICAL_FALLBACK;

                if (! isset($logs[$key])) {
                    $unresolved++;
                }

                if (count($samples) < 20) {
                    $samples[] = [
                        'mapping_id' => $mapping->id,
                        'product_id' => $mapping->product_id,
                        'channel_shop_id' => $mapping->channel_shop_id,
                        'new_error_message' => $newMessage,
                        'source' => isset($logs[$key]) ? 'product_sync_logs' : 'historical_fallback',
                    ];
                }

                if (! $apply) {
                    continue;
                }

                $updated += ProductChannelMapping::query()
                    ->whereKey($mapping->id)
                    ->where('sync_status', ProductChannelMapping::STATUS_FAILED)
                    ->update([
                        'error_message' => $newMessage,
                        'updated_at' => now(),
                    ]);
            }
        }, 'id');

        $this->info($apply ? 'REPAIR APPLY' : 'REPAIR DRY-RUN');
        $this->line("Mapping dipindai: {$scanned}");
        $this->line("Mapping yang perlu diperbaiki: {$eligible}");
        $this->line("Detail historis tidak ditemukan: {$unresolved}");
        $this->line($apply ? "Mapping diperbarui: {$updated}" : 'Tidak ada perubahan disimpan.');

        if ($samples !== []) {
            $this->newLine();
            $this->table(
                ['Mapping', 'Product', 'Shop', 'Sumber', 'Pesan baru'],
                array_map(static fn (array $sample): array => [
                    $sample['mapping_id'],
                    $sample['product_id'],
                    $sample['channel_shop_id'],
                    $sample['source'],
                    $sample['new_error_message'],
                ], $samples),
            );
        }

        return self::SUCCESS;
    }

    private function latestActionableLogs(Collection $mappings): array
    {
        $pairs = $mappings
            ->filter(fn (ProductChannelMapping $mapping): bool => $mapping->product_id && $mapping->channel_shop_id)
            ->map(fn (ProductChannelMapping $mapping): array => [
                'product_id' => $mapping->product_id,
                'channel_shop_id' => $mapping->channel_shop_id,
            ])
            ->unique(fn (array $pair): string => $this->key($pair['product_id'], $pair['channel_shop_id']))
            ->values();

        if ($pairs->isEmpty()) {
            return [];
        }

        $rankedLogs = ProductSyncLog::query()
            ->where('action', ProductSyncLog::ACTION_UPLOAD)
            ->where('status', ProductSyncLog::STATUS_FAILED)
            ->whereNotNull('error_message')
            ->where(function ($query) use ($pairs): void {
                foreach ($pairs as $pair) {
                    $query->orWhere(function ($pairQuery) use ($pair): void {
                        $pairQuery
                            ->where('product_id', $pair['product_id'])
                            ->where('channel_shop_id', $pair['channel_shop_id']);
                    });
                }
            })
            ->whereRaw("LOWER(COALESCE(error_message, '')) NOT LIKE ?", ['%too many%'])
            ->whereRaw("LOWER(COALESCE(error_message, '')) NOT LIKE ?", ['%maxattemptsexceeded%'])
            ->whereRaw("LOWER(COALESCE(error_message, '')) NOT LIKE ?", ['%attempted too many times%'])
            ->select(['product_id', 'channel_shop_id', 'error_message'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY product_id, channel_shop_id ORDER BY created_at DESC, id DESC) as row_number');

        return DB::query()
            ->fromSub($rankedLogs, 'ranked_logs')
            ->where('row_number', 1)
            ->get(['product_id', 'channel_shop_id', 'error_message'])
            ->mapWithKeys(fn (object $log): array => [
                $this->key($log->product_id, $log->channel_shop_id) => $log->error_message,
            ])
            ->all();
    }

    private function key(?string $productId, ?string $shopId): string
    {
        return (string) $productId.':'.(string) $shopId;
    }
}
