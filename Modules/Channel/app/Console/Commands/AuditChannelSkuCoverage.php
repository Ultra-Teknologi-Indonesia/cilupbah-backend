<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelDownloadService;

class AuditChannelSkuCoverage extends Command
{
    protected $signature = 'channel:audit-sku-coverage
                            {--channel= : Batasi ke satu channel (shopee|tiktok|lazada|woocommerce)}
                            {--shop= : Batasi ke satu shop_id marketplace}
                            {--apply : Tarik ulang produk dari channel (tanpa ini hanya laporan)}';

    protected $description = 'Audit apakah semua SKU varian aktif di channel sudah berada di satu master per listing';

    public function handle(ChannelDownloadService $downloader): int
    {
        $shops = $this->resolveShops();

        if ($shops->isEmpty()) {
            $this->error('Tidak ada toko aktif yang cocok dengan filter.');

            return self::FAILURE;
        }

        $this->line('Toko dalam cakupan: ' . $shops->count());

        if ($this->option('apply')) {
            $this->tarikUlang($downloader, $shops);
        } else {
            $this->warn('PRATINJAU — tidak ada penarikan ulang. Tambahkan --apply untuk menarik dari channel.');
        }

        $this->newLine();
        $this->laporkanListingTerpecah($shops);
        $this->newLine();
        $this->laporkanModelDilewati($shops);
        $this->newLine();
        $this->laporkanVarianTanpaSku($shops);

        return self::SUCCESS;
    }

    private function resolveShops()
    {
        $query = ChannelShop::query()->with('channel')->where('is_active', true);

        if ($channel = $this->option('channel')) {
            $query->whereHas('channel', fn ($q) => $q->where('code', strtolower($channel)));
        }

        if ($shopId = $this->option('shop')) {
            $query->where('shop_id', $shopId);
        }

        return $query->get();
    }

    private function tarikUlang(ChannelDownloadService $downloader, $shops): void
    {
        foreach ($shops as $shop) {
            $code = strtolower((string) ($shop->channel->code ?? ''));

            if (! $code) {
                continue;
            }

            $this->line("Menarik {$code} / {$shop->shop_name} ...");

            try {
                $downloader->pull($code, $shop->shop_id);
            } catch (\Throwable $e) {
                $this->error("  gagal: {$e->getMessage()}");
            }
        }
    }

    private function laporkanListingTerpecah($shops): void
    {
        $rows = DB::table('product_channel_mappings')
            ->select('channel_shop_id', 'external_product_id')
            ->selectRaw('count(DISTINCT product_id) AS jml_master')
            ->whereIn('channel_shop_id', $shops->pluck('id'))
            ->whereNotNull('external_product_id')
            ->groupBy('channel_shop_id', 'external_product_id')
            ->havingRaw('count(DISTINCT product_id) > 1')
            ->get();

        $this->line('== Listing yang mendarat di lebih dari satu master: ' . $rows->count());

        if ($rows->isEmpty()) {
            $this->info('   Bersih — setiap listing hanya punya satu master.');

            return;
        }

        $namaToko = $shops->pluck('shop_name', 'id');

        foreach ($rows->take(30) as $row) {
            $this->line("   {$namaToko[$row->channel_shop_id]} / item {$row->external_product_id} → {$row->jml_master} master");
        }

        if ($rows->count() > 30) {
            $this->line('   ... dan ' . ($rows->count() - 30) . ' lainnya (dipotong di 30)');
        }
    }

    private function laporkanModelDilewati($shops): void
    {
        $rows = DB::table('product_sync_logs')
            ->select('error_message')
            ->selectRaw('count(*) AS jml')
            ->whereIn('channel_shop_id', $shops->pluck('id'))
            ->where('action', 'download')
            ->where('status', 'failed')
            ->where('created_at', '>', now()->subDay())
            ->groupBy('error_message')
            ->orderByDesc('jml')
            ->get();

        $this->line('== Model channel yang dilewati (24 jam terakhir)');

        if ($rows->isEmpty()) {
            $this->info('   Tidak ada model yang dilewati.');

            return;
        }

        foreach ($rows as $row) {
            $this->line('   ' . str_pad((string) $row->jml, 6) . $row->error_message);
        }
    }

    private function laporkanVarianTanpaSku($shops): void
    {
        $jml = DB::table('product_variants')
            ->join('product_variant_channel_mappings', 'product_variant_channel_mappings.variant_id', '=', 'product_variants.id')
            ->join('product_channel_mappings', 'product_channel_mappings.id', '=', 'product_variant_channel_mappings.product_channel_mapping_id')
            ->whereIn('product_channel_mappings.channel_shop_id', $shops->pluck('id'))
            ->whereNull('product_variants.deleted_at')
            ->whereNull('product_variants.sku')
            ->distinct()
            ->count('product_variants.id');

        $this->line('== Varian tanpa SKU yang masih tertaut ke channel: ' . $jml);

        if ($jml === 0) {
            $this->info('   Bersih — tidak ada varian kosong yang tertaut.');

            return;
        }

        $this->warn('   Sisa dari download lama. Tarik ulang dengan --apply agar tautannya dilepas.');
    }
}
