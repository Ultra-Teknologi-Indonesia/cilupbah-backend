<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Modules\Product\Services\ChannelSkuHealth;

class ReportMissingChannelSku extends Command
{
    protected $signature = 'channel:report-missing-sku
                            {--hours=24 : Rentang jam ke belakang}
                            {--channel= : Batasi ke satu channel}
                            {--top=40 : Jumlah listing yang ditampilkan}
                            {--csv= : Tulis seluruh baris ke berkas CSV}';

    protected $description = 'Daftar listing yang punya model tanpa SKU, per toko, siap dikerjakan tim katalog';

    public function __construct(private ChannelSkuHealth $health)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $jam = max(1, (int) $this->option('hours'));
        $rows = $this->health->modelTanpaSku($jam, $this->option('channel'));

        if ($rows->isEmpty()) {
            $this->info("Tidak ada model tanpa SKU dalam {$jam} jam terakhir.");

            return self::SUCCESS;
        }

        $totalModel = $rows->sum('jml');

        $this->line("{$totalModel} model tanpa SKU di {$rows->count()} listing ({$jam} jam terakhir).");
        $this->newLine();

        foreach ($rows->groupBy('channel') as $channel => $perChannel) {
            $this->line(strtoupper($channel) . ' — ' . $perChannel->sum('jml') . ' model di ' . $perChannel->count() . ' listing');
        }

        $this->newLine();
        $this->table(
            ['Channel', 'Toko', 'Listing', 'Model', 'Tautan'],
            $rows->take((int) $this->option('top'))->map(fn ($r) => [
                $r->channel,
                mb_strimwidth((string) $r->shop_name, 0, 24, '…'),
                $r->listing,
                $r->jml,
                $r->url ?: $this->tautanSellerCenter($r->channel, $r->listing),
            ])->all()
        );

        if ($rows->count() > (int) $this->option('top')) {
            $this->line('... dan ' . ($rows->count() - (int) $this->option('top')) . ' listing lain — pakai --csv untuk daftar penuh.');
        }

        if ($berkas = $this->option('csv')) {
            $this->tulisCsv($berkas, $rows);
        }

        return self::SUCCESS;
    }

    private function tautanSellerCenter(string $channel, string $listing): string
    {
        return match (strtolower($channel)) {
            'shopee' => "https://seller.shopee.co.id/portal/product/{$listing}",
            'lazada' => "https://sellercenter.lazada.co.id/apps/product/edit?ItemId={$listing}",
            'tiktok' => "https://seller-id.tiktok.com/product/edit?product_id={$listing}",
            default => '',
        };
    }

    private function tulisCsv(string $berkas, $rows): void
    {
        $handle = @fopen($berkas, 'w');

        if (! $handle) {
            $this->error("Tidak bisa menulis ke {$berkas}.");

            return;
        }

        fputcsv($handle, ['channel', 'toko', 'shop_id', 'listing', 'jumlah_model', 'tautan']);

        foreach ($rows as $r) {
            fputcsv($handle, [
                $r->channel,
                $r->shop_name,
                $r->shop_id,
                $r->listing,
                $r->jml,
                $r->url ?: $this->tautanSellerCenter($r->channel, $r->listing),
            ]);
        }

        fclose($handle);

        $this->info("CSV ditulis: {$berkas} ({$rows->count()} baris)");
    }
}
