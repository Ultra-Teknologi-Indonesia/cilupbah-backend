<?php

namespace Modules\Product\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Product\Services\ChannelSkuHealth;

class RepairChannelSku extends Command
{
    protected $signature = 'products:repair-channel-sku
                            {--apply : Terapkan perbaikan (tanpa ini hanya laporan)}
                            {--only= : Batasi ke satu bagian (master|varian)}
                            {--product= : Batasi ke satu id master}';

    protected $description = 'Bersihkan sisa SKU salah dari download channel: SKU master yang diturunkan dari SKU varian, dan varian ber-SKU placeholder';

    private const CONTOH = 20;

    public function __construct(private ChannelSkuHealth $health)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $only = strtolower((string) $this->option('only'));

        if ($only !== '' && ! in_array($only, ['master', 'varian'], true)) {
            $this->error('--only hanya menerima "master" atau "varian".');

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->warn('PRATINJAU — tidak ada perubahan data. Tambahkan --apply untuk menerapkan.');
            $this->newLine();
        }

        if ($only !== 'varian') {
            $this->bagianMasterSku();
            $this->newLine();
        }

        if ($only !== 'master') {
            $this->bagianVarianPlaceholder();
        }

        return self::SUCCESS;
    }

    private function bagianMasterSku(): void
    {
        $rows = $this->health->masterSkuTurunanVarian($this->option('product'));

        $this->line('== SKU master yang diturunkan dari SKU varian: ' . $rows->count());
        $this->laporkanMasterSkuTakDikenal($rows->pluck('id')->all());

        if ($rows->isEmpty()) {
            $this->info('   Bersih — tidak ada SKU induk yang menyalin SKU varian.');

            return;
        }

        $sendiri = $rows->where('varian_sendiri', true)->count();
        $this->line('   ' . $sendiri . ' cocok dengan varian miliknya sendiri, '
            . ($rows->count() - $sendiri) . ' dengan varian yang sudah pindah master.');

        foreach ($rows->take(self::CONTOH) as $row) {
            $this->line('   ' . str_pad($row->sku, 32) . mb_strimwidth($row->name, 0, 60, '…'));
        }

        if ($rows->count() > self::CONTOH) {
            $this->line('   ... dan ' . ($rows->count() - self::CONTOH) . ' lainnya');
        }

        if (! $this->option('apply')) {
            return;
        }

        $terhapus = 0;

        foreach ($rows->pluck('id')->chunk(500) as $chunk) {
            $terhapus += DB::table('products')
                ->whereIn('id', $chunk->all())
                ->update(['sku' => null, 'updated_at' => now()]);
        }

        $this->info('   SKU induk dikosongkan: ' . $terhapus . ' master.');
    }

    private function laporkanMasterSkuTakDikenal(array $sudahDitangani): void
    {
        $jml = $this->health->masterSkuTakDikenal($sudahDitangani, $this->option('product'));

        if ($jml === 0) {
            return;
        }

        $this->line('   (' . $jml . ' master lain punya SKU induk yang bukan SKU varian — tidak disentuh,');
        $this->line('    itu bisa SKU induk asli dari Seller Center atau isian manual.)');
    }

    private function bagianVarianPlaceholder(): void
    {
        $placeholder = $this->health->varianPlaceholder($this->option('product'));

        $this->line('== Varian ber-SKU placeholder channel: ' . $placeholder->count());

        if ($placeholder->isEmpty()) {
            $this->info('   Bersih — tidak ada varian yang lahir dari SKU palsu.');

            return;
        }

        $terpakai = $this->varianTerpakai($placeholder->pluck('id')->all());

        $bisaDihapus = $placeholder->reject(fn ($row) => isset($terpakai[$row->id]));
        $terakhir = $this->varianYangMengosongkanMaster($bisaDihapus);

        $aman = $bisaDihapus->reject(fn ($row) => isset($terakhir[$row->id]))->values();

        $this->line('   ' . $aman->count() . ' aman dihapus, '
            . count($terpakai) . ' masih dipakai pesanan/stok, '
            . count($terakhir) . ' akan mengosongkan masternya.');

        foreach ($placeholder->take(self::CONTOH) as $row) {
            $tanda = isset($terpakai[$row->id]) ? ' [dipakai]'
                : (isset($terakhir[$row->id]) ? ' [varian terakhir master]' : '');
            $asal = $row->external_product_id ? 'listing ' . $row->external_product_id : 'tanpa tautan channel';
            $this->line('   ' . str_pad($row->sku, 34) . $asal . $tanda);
        }

        if ($placeholder->count() > self::CONTOH) {
            $this->line('   ... dan ' . ($placeholder->count() - self::CONTOH) . ' lainnya');
        }

        if ($terpakai || $terakhir) {
            $this->warn('   Yang ditandai dilewati — perlu keputusan manual, bukan dihapus otomatis.');
        }

        if (! $this->option('apply') || $aman->isEmpty()) {
            return;
        }

        $ids = $aman->pluck('id')->all();
        $tautan = 0;

        DB::transaction(function () use ($ids, &$tautan) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $tautan += DB::table('product_variant_channel_mappings')
                    ->whereIn('variant_id', $chunk)
                    ->delete();

                DB::table('variant_options')->whereIn('variant_id', $chunk)->delete();

                DB::table('product_variants')
                    ->whereIn('id', $chunk)
                    ->update(['deleted_at' => now(), 'updated_at' => now()]);
            }
        });

        $this->info('   Varian dihapus: ' . count($ids) . ', tautan channel dilepas: ' . $tautan . '.');
    }

    private function varianTerpakai(array $variantIds): array
    {
        $dipakai = [];

        foreach (array_chunk($variantIds, 500) as $chunk) {
            foreach (DB::table('sales_order_items')->whereIn('item_id', $chunk)->distinct()->pluck('item_id') as $id) {
                $dipakai[$id] = true;
            }

            $berstok = DB::table('inventories')
                ->whereIn('item_id', $chunk)
                ->where(fn ($q) => $q->where('on_hand', '<>', 0)->orWhere('on_order', '<>', 0))
                ->distinct()
                ->pluck('item_id');

            foreach ($berstok as $id) {
                $dipakai[$id] = true;
            }
        }

        return $dipakai;
    }

    private function varianYangMengosongkanMaster($kandidat): array
    {
        $perMaster = $kandidat->groupBy('product_id');

        if ($perMaster->isEmpty()) {
            return [];
        }

        $jumlahAktif = DB::table('product_variants')
            ->whereIn('product_id', $perMaster->keys()->all())
            ->whereNull('deleted_at')
            ->groupBy('product_id')
            ->selectRaw('product_id, count(*) AS jml')
            ->pluck('jml', 'product_id');

        $terakhir = [];

        foreach ($perMaster as $productId => $rows) {
            $sisa = (int) ($jumlahAktif[$productId] ?? 0) - $rows->count();

            if ($sisa > 0) {
                continue;
            }

            foreach ($rows as $row) {
                $terakhir[$row->id] = true;
            }
        }

        return $terakhir;
    }
}
