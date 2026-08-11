<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelLiveStockReader;
use Modules\Channel\Services\ChannelStockResolver;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;

/**
 * Rekonsiliasi stok tiga arah sebelum serah terima: WMS vs angka live di
 * listing marketplace vs sistem lama (CSV, opsional).
 *
 * Command ini sekaligus berfungsi sebagai dry-run push: angka WMS yang
 * ditampilkan dihitung lewat ChannelStockResolver — jalur yang sama persis
 * dipakai push sungguhan, termasuk mode sumber stok, perhitungan bundle, dan
 * buffer pengaman. Jadi kalau angkanya salah di sini, ia akan salah juga saat
 * dikirim; bedanya di sini tidak ada yang terkirim.
 */
class StockReconcileCommand extends Command
{
    protected $signature = 'channel:stock-reconcile
        {--shop= : Batasi ke satu shop_id marketplace}
        {--jubelio= : Path CSV export stok sistem lama (kolom: sku, qty)}
        {--tolerance=0 : Selisih unit yang masih dianggap cocok}
        {--limit= : Batasi jumlah produk yang diperiksa (uji cepat)}
        {--only-diff : Tampilkan hanya SKU yang selisih}
        {--json= : Path file hasil. Default storage/app/stock-reconcile/}';

    protected $description = 'Bandingkan stok WMS dengan stok yang tayang di marketplace (dan sistem lama), tanpa mengirim apa pun.';

    public function handle(ChannelStockResolver $resolver, ChannelLiveStockReader $liveReader): int
    {
        $tolerance = (int) $this->option('tolerance');
        $jubelio = $this->readJubelioCsv($this->option('jubelio'));

        if ($this->option('jubelio') && $jubelio === null) {
            $this->error('CSV stok sistem lama wajib punya kolom sku dan qty.');

            return self::FAILURE;
        }

        $shops = ChannelShop::with('channel')
            ->where('is_active', true)
            ->when($this->option('shop'), fn ($query, $shopId) => $query->where('shop_id', $shopId))
            ->get();

        if ($shops->isEmpty()) {
            $this->info('Tidak ada toko aktif yang cocok.');

            return self::SUCCESS;
        }

        $results = [];

        foreach ($shops as $shop) {
            $this->line("Memeriksa {$shop->shop_name} ({$shop->channel->code}) ...");
            $results[] = $this->reconcileShop($shop, $resolver, $liveReader, $jubelio, $tolerance);
        }

        $path = $this->writeResults($results);
        $this->line("Hasil disimpan: {$path}");

        return self::SUCCESS;
    }

    private function reconcileShop(
        ChannelShop $shop,
        ChannelStockResolver $resolver,
        ChannelLiveStockReader $liveReader,
        ?array $jubelio,
        int $tolerance,
    ): array {
        $channelCode = $shop->channel->code ?? 'unknown';

        $mappings = ProductChannelMapping::query()
            ->where('channel_shop_id', $shop->id)
            ->whereNotNull('external_product_id')
            ->when($this->option('limit'), fn ($query, $limit) => $query->limit((int) $limit))
            ->get();

        $rows = [];
        $matched = 0;
        $mismatched = 0;
        $unreadable = 0;

        foreach ($mappings as $mapping) {
            $product = Product::with('variants.channelMappings.channelMapping')->find($mapping->product_id);

            if (! $product) {
                continue;
            }

            $wmsByVariant = $resolver->availableByVariant($shop, $product->variants);
            $liveBySku = $liveReader->read($channelCode, $shop->shop_id, (string) $mapping->external_product_id);

            if ($liveBySku === []) {
                $unreadable++;
            }

            foreach ($product->variants as $variant) {
                $variantMapping = $variant->channelMappings
                    ->firstWhere(fn ($vm) => (string) optional($vm->channelMapping)->channel_shop_id === (string) $shop->id);

                if (! $variantMapping || ! $variantMapping->sync_enabled) {
                    continue;
                }

                $externalSkuId = (string) ($variantMapping->external_sku_id ?? '');
                $wms = (int) ($wmsByVariant[$variant->id] ?? 0);
                $live = array_key_exists($externalSkuId, $liveBySku) ? (int) $liveBySku[$externalSkuId] : null;
                $old = $jubelio[strtoupper((string) $variant->sku)] ?? null;

                $diff = $live === null ? null : $wms - $live;
                $isMatch = $diff !== null && abs($diff) <= $tolerance;

                if ($live !== null) {
                    $isMatch ? $matched++ : $mismatched++;
                }

                if ($this->option('only-diff') && $isMatch) {
                    continue;
                }

                $rows[] = [
                    'sku'          => $variant->sku,
                    'wms'          => $wms,
                    'live'         => $live,
                    'jubelio'      => $old,
                    'diff_vs_live' => $diff,
                ];
            }
        }

        $checked = $matched + $mismatched;

        $this->renderShop($shop, $rows, [
            'checked'     => $checked,
            'matched'     => $matched,
            'mismatched'  => $mismatched,
            'unreadable'  => $unreadable,
            'match_rate'  => $checked > 0 ? round($matched / $checked * 100, 2) : null,
        ]);

        return [
            'shop_id'            => $shop->shop_id,
            'shop_name'          => $shop->shop_name,
            'channel'            => $channelCode,
            'stock_push_enabled' => (bool) $shop->stock_push_enabled,
            'stock_push_buffer'  => (int) $shop->stock_push_buffer,
            'checked'            => $checked,
            'matched'            => $matched,
            'mismatched'         => $mismatched,
            'listing_unreadable' => $unreadable,
            'match_rate'         => $checked > 0 ? round($matched / $checked * 100, 2) : null,
            'rows'               => $rows,
        ];
    }

    private function renderShop(ChannelShop $shop, array $rows, array $summary): void
    {
        $this->newLine();
        $this->line("<info>{$shop->shop_name}</info>");

        $this->table(
            ['Diperiksa', 'Cocok', 'Selisih', 'Listing tak terbaca', 'Match rate'],
            [[
                $summary['checked'],
                $summary['matched'],
                $summary['mismatched'],
                $summary['unreadable'],
                $summary['match_rate'] === null ? '-' : $summary['match_rate'] . '%',
            ]],
        );

        if ($rows === []) {
            return;
        }

        $preview = array_slice($rows, 0, 20);

        $this->table(
            ['SKU', 'WMS (akan dikirim)', 'Live listing', 'Jubelio', 'Selisih'],
            array_map(fn ($row) => [
                $row['sku'],
                $row['wms'],
                $row['live'] ?? '-',
                $row['jubelio'] ?? '-',
                $row['diff_vs_live'] ?? '-',
            ], $preview),
        );

        if (count($rows) > count($preview)) {
            $this->line('... ' . (count($rows) - count($preview)) . ' baris lagi, lihat file hasil.');
        }
    }

    /**
     * @return array<string, int>|null
     */
    private function readJubelioCsv(?string $path): ?array
    {
        if (! $path) {
            return null;
        }

        if (! is_readable($path)) {
            $this->error("File stok sistem lama tidak terbaca: {$path}");

            return null;
        }

        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return null;
        }

        $columns = array_flip(array_map(fn ($name) => strtolower(trim((string) $name)), $header));

        if (! isset($columns['sku']) || ! isset($columns['qty'])) {
            fclose($handle);

            return null;
        }

        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $sku = strtoupper(trim((string) ($row[$columns['sku']] ?? '')));

            if ($sku === '') {
                continue;
            }

            $rows[$sku] = (int) trim((string) ($row[$columns['qty']] ?? '0'));
        }

        fclose($handle);

        return $rows;
    }

    private function writeResults(array $results): string
    {
        $path = $this->option('json') ?: 'stock-reconcile/' . now()->format('Ymd-His') . '.json';

        Storage::put($path, json_encode([
            'generated_at' => now()->toIso8601String(),
            'tolerance'    => (int) $this->option('tolerance'),
            'shops'        => $results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Storage::path($path);
    }
}
