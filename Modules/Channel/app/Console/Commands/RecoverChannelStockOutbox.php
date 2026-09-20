<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Modules\Channel\Services\ChannelStockSyncOutboxService;
use Modules\Channel\Support\ChannelVariantMappingResolver;
use Modules\Product\Models\ProductChannelMapping;

class RecoverChannelStockOutbox extends Command
{
    protected $signature = 'channel:recover-stock-outbox
        {--apply : Simpan listing valid ke outbox; tanpa opsi ini hanya dry-run}
        {--channel= : Batasi ke kode channel, misalnya shopee}
        {--shop= : Batasi ke ID internal atau ID toko channel}
        {--limit=0 : Batas listing yang dipindai; 0 berarti semua}';

    protected $description = 'Membangun ulang push stok dari keadaan lokal terbaru secara aman dan terkoalesensi.';

    public function handle(ChannelStockSyncOutboxService $outbox): int
    {
        $apply = (bool) $this->option('apply');
        $rawLimit = (string) $this->option('limit');
        if (! ctype_digit($rawLimit)) {
            $this->error('--limit harus berupa 0 atau bilangan bulat positif.');

            return self::FAILURE;
        }

        $limit = (int) $rawLimit;
        $scanned = 0;
        $queued = 0;
        $skippedDisabled = 0;
        $skippedInvalidMapping = 0;
        $samples = [];

        $query = ProductChannelMapping::query()
            ->where('sync_status', '!=', ProductChannelMapping::STATUS_DEACTIVATED)
            ->whereNotNull('external_product_id')
            ->where('external_product_id', '!=', '')
            ->whereHas('product', fn (Builder $builder) => $builder->where('is_active', true))
            ->whereHas('channelShop', fn (Builder $builder) => $builder->where('stock_push_enabled', true))
            ->with(['variantMappings.variant', 'channelShop.channel'])
            ->when($this->option('channel'), function (Builder $builder, string $channel): void {
                $builder->whereHas('channelShop.channel', fn (Builder $channelQuery) => $channelQuery->where('code', $channel));
            })
            ->when($this->option('shop'), function (Builder $builder, string $shop): void {
                $builder->whereHas('channelShop', function (Builder $shopQuery) use ($shop): void {
                    $shopQuery->where('id', $shop)->orWhere('shop_id', $shop);
                });
            })
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $query->chunkById(250, function ($mappings) use (
            $apply,
            $outbox,
            &$scanned,
            &$queued,
            &$skippedDisabled,
            &$skippedInvalidMapping,
            &$samples,
        ): void {
            foreach ($mappings as $mapping) {
                $scanned++;

                if ($this->listingSyncFullyDisabled($mapping)) {
                    $skippedDisabled++;

                    continue;
                }

                if (ChannelVariantMappingResolver::hasEnabledMappings($mapping)
                    && ChannelVariantMappingResolver::enabledForListing($mapping)->isEmpty()) {
                    $skippedInvalidMapping++;

                    continue;
                }

                if (count($samples) < 20) {
                    $samples[] = [
                        'channel' => $mapping->channelShop?->channel?->code ?? '-',
                        'shop' => $mapping->channelShop?->shop_id ?? $mapping->channel_shop_id,
                        'listing' => $mapping->external_product_id,
                        'mapping' => $mapping->id,
                    ];
                }

                if ($apply) {
                    $outbox->request($mapping, 'sync_stock', 'bulk');
                }

                $queued++;
            }
        }, 'id');

        $this->info($apply ? 'RECOVERY APPLY' : 'RECOVERY DRY-RUN');
        $this->line("Listing dipindai: {$scanned}");
        $this->line($apply ? "Listing masuk/di-update di outbox: {$queued}" : "Listing layak masuk outbox: {$queued}");
        $this->line("Lewati karena sinkronisasi listing dimatikan: {$skippedDisabled}");
        $this->line("Lewati karena mapping varian tidak valid: {$skippedInvalidMapping}");
        $this->line($apply
            ? 'Tidak ada job lama yang diulang; worker akan mengirim angka stok terbaru sesuai kuota channel.'
            : 'Tidak ada perubahan disimpan. Jalankan kembali dengan --apply setelah hasil diverifikasi.');

        if ($samples !== []) {
            $this->newLine();
            $this->table(['Channel', 'Toko', 'Listing', 'Mapping'], $samples);
        }

        return self::SUCCESS;
    }

    private function listingSyncFullyDisabled(ProductChannelMapping $mapping): bool
    {
        $variantMappings = $mapping->relationLoaded('variantMappings')
            ? $mapping->variantMappings
            : $mapping->variantMappings()->get(['sync_enabled']);

        return $variantMappings->isNotEmpty()
            && $variantMappings->every(fn ($variantMapping): bool => ! $variantMapping->sync_enabled);
    }
}
