<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Services\ChannelSkuHealth;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class AuditChannelSkuCoverage extends Command
{
    protected $signature = 'channel:audit-sku-coverage
                            {--channel= : Batasi ke satu channel (shopee|tiktok|lazada|woocommerce)}
                            {--shop= : Batasi ke satu shop_id marketplace}
                            {--apply : Tarik ulang produk dari channel (tanpa ini hanya laporan)}
                            {--timeout=1800 : Batas waktu penarikan per toko, dalam detik}';

    protected $description = 'Audit apakah semua SKU varian aktif di channel sudah berada di satu master per listing';

    public function __construct(private ChannelSkuHealth $skuHealth)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $shops = $this->resolveShops();

        if ($shops->isEmpty()) {
            $this->error('Tidak ada toko aktif yang cocok dengan filter.');

            return self::FAILURE;
        }

        $this->line('Toko dalam cakupan: '.$shops->count());

        if ($this->option('apply')) {
            $this->tarikUlang($shops);
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

    private function tarikUlang($shops): void
    {
        foreach ($shops as $shop) {
            $code = strtolower((string) ($shop->channel->code ?? ''));

            if (! $code) {
                continue;
            }

            $this->line("Menarik {$code} / {$shop->shop_name} ...");

            $proses = new Process(
                [PHP_BINARY, 'artisan', 'channel:pull-shop', $code, (string) $shop->shop_id],
                base_path(),
                null,
                null,
                (float) $this->option('timeout')
            );

            try {
                $proses->run(fn ($jenis, $keluaran) => $this->output->write($keluaran));
            } catch (ProcessTimedOutException) {
                $this->error('  gagal: melewati batas waktu '.$this->option('timeout').' detik');

                continue;
            }

            if (! $proses->isSuccessful()) {
                $this->error('  gagal: keluar dengan kode '.$proses->getExitCode());
            }
        }
    }

    private function laporkanListingTerpecah($shops): void
    {
        $rows = $this->skuHealth->multiMasterListingDetails($shops->pluck('id'));
        $validBundles = $rows->where('valid_bundle_split', true)->values();
        $invalid = $rows->where('valid_bundle_split', false)->values();

        $this->line('== Listing bundle yang sah dibagi ke beberapa master: '.$validBundles->count());
        if ($validBundles->isNotEmpty()) {
            $this->info('   Setiap SKU penjual cocok dengan SKU bundle masing-masing; ini memang diperlukan agar stok bundle tersinkron.');
        }

        $this->line('== Listing yang bermasalah di lebih dari satu master: '.$invalid->count());

        if ($invalid->isEmpty()) {
            $this->info('   Bersih — setiap listing non-bundle hanya punya satu master.');

            return;
        }

        $namaToko = $shops->pluck('shop_name', 'id');

        foreach ($invalid->take(30) as $row) {
            $this->line("   {$namaToko[$row->channel_shop_id]} / item {$row->external_product_id} → {$row->jml_master} master");
        }

        if ($invalid->count() > 30) {
            $this->line('   ... dan '.($invalid->count() - 30).' lainnya (dipotong di 30)');
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
            $this->line('   '.str_pad((string) $row->jml, 6).$row->error_message);
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

        $this->line('== Varian tanpa SKU yang masih tertaut ke channel: '.$jml);

        if ($jml === 0) {
            $this->info('   Bersih — tidak ada varian kosong yang tertaut.');

            return;
        }

        $this->warn('   Sisa dari download lama. Linker baru tidak pernah menautkan model tanpa SKU,');
        $this->warn('   tapi baris lama TIDAK hilang sendiri saat --apply — hanya ikut terhapus bila');
        $this->warn('   pcm-nya dibuang saat konsolidasi. Sisanya perlu dibersihkan manual, dan cek');
        $this->warn('   dulu sales_order_items.item_id serta inventories.item_id (on_hand & on_order)');
        $this->warn('   harus nol sebelum menghapus.');
    }
}
