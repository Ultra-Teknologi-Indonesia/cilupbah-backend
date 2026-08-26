<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductSyncLog;

class RepairStaleSyncErrors extends Command
{
    protected $signature = 'channel:repair-stale-sync-errors
        {--apply : Terapkan perubahan (tanpa ini hanya dry-run)}
        {--limit= : Batasi jumlah mapping yang dipindai}';

    protected $description = 'Perbaiki pesan error mapping channel yang tertimpa pesan retry generik';

    private const CHUNK_SIZE = 500;

    private const HISTORICAL_FALLBACK =
        'Sinkronisasi ke channel gagal setelah beberapa percobaan. Detail error historis tidak tersimpan; silakan sinkronisasi ulang.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = $this->option('limit');

        if ($limit !== null && (! ctype_digit((string) $limit) || (int) $limit < 1)) {
            $this->error('--limit harus berupa angka positif.');

            return self::FAILURE;
        }

        $query = ProductChannelMapping::query()
            ->where('sync_status', ProductChannelMapping::STATUS_FAILED)
            ->where(function ($query) {
                $query
                    ->whereRaw("LOWER(COALESCE(error_message, '')) LIKE ?", ['%too many%attempt%'])
                    ->orWhereRaw("LOWER(COALESCE(error_message, '')) LIKE ?", ['%maxattemptsexceeded%'])
                    ->orWhereRaw("LOWER(COALESCE(error_message, '')) LIKE ?", ['%attempted too many times%']);
            })
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit((int) $limit);
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
        $productIds = $mappings->pluck('product_id')->filter()->unique()->values();
        $shopIds = $mappings->pluck('channel_shop_id')->filter()->unique()->values();

        if ($productIds->isEmpty() || $shopIds->isEmpty()) {
            return [];
        }

        return ProductSyncLog::query()
            ->where('action', ProductSyncLog::ACTION_UPLOAD)
            ->where('status', ProductSyncLog::STATUS_FAILED)
            ->whereIn('product_id', $productIds)
            ->whereIn('channel_shop_id', $shopIds)
            ->whereNotNull('error_message')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['product_id', 'channel_shop_id', 'error_message'])
            ->filter(fn (ProductSyncLog $log): bool => ! $this->isGenericRetryMessage($log->error_message))
            ->unique(fn (ProductSyncLog $log): string => $this->key($log->product_id, $log->channel_shop_id))
            ->mapWithKeys(fn (ProductSyncLog $log): array => [
                $this->key($log->product_id, $log->channel_shop_id) => $log->error_message,
            ])
            ->all();
    }

    private function key(?string $productId, ?string $shopId): string
    {
        return (string) $productId . ':' . (string) $shopId;
    }

    private function isGenericRetryMessage(?string $message): bool
    {
        $message = strtolower(trim((string) $message));

        return str_contains($message, 'too many')
            || str_contains($message, 'maxattemptsexceeded')
            || str_contains($message, 'attempted too many times');
    }
}
