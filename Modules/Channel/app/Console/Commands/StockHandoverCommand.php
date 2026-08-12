<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Jobs\ResyncShopStockJob;
use Modules\Channel\Models\ChannelShop;

class StockHandoverCommand extends Command
{
    protected $signature = 'channel:stock-handover
        {--shop= : shop_id marketplace yang diserahterimakan}
        {--buffer=0 : Buffer pengaman (unit) yang dikurangkan dari stok yang dikirim}
        {--dry-run : Tampilkan rencananya tanpa mengubah apa pun}
        {--force : Jalankan tanpa konfirmasi}';

    protected $description = 'Aktifkan push stok ke marketplace untuk satu toko (serah terima dari sistem lama).';

    public function handle(): int
    {
        $shopId = $this->option('shop');

        if (! $shopId) {
            $this->error('Opsi --shop wajib diisi. Serah terima dilakukan per toko, tidak serentak.');

            return self::FAILURE;
        }

        $shop = ChannelShop::with('channel')->where('shop_id', $shopId)->first();

        if (! $shop) {
            $this->error("Toko {$shopId} tidak ditemukan.");

            return self::FAILURE;
        }

        $buffer = max(0, (int) $this->option('buffer'));
        $isDryRun = (bool) $this->option('dry-run');

        $this->table(['Field', 'Sekarang', 'Setelah'], [
            ['Toko', $shop->shop_name, $shop->shop_name],
            ['Channel', $shop->channel->code ?? '-', $shop->channel->code ?? '-'],
            ['Shadow order', $shop->is_shadow_mode ? 'aktif' : 'nonaktif', $shop->is_shadow_mode ? 'aktif' : 'nonaktif'],
            ['Push stok', $shop->stock_push_enabled ? 'aktif' : 'nonaktif', 'aktif'],
            ['Buffer', (int) $shop->stock_push_buffer, $buffer],
        ]);

        if ($shop->is_shadow_mode) {
            $this->error('Toko ini masih dalam mode shadow order. Selesaikan cutover order lebih dulu (channel:shadow-off), baru serah terima stok.');

            return self::FAILURE;
        }

        if ($shop->stock_push_enabled && ! $isDryRun) {
            $this->info('Push stok untuk toko ini sudah aktif. Tidak ada yang diubah.');

            return self::SUCCESS;
        }

        if ($isDryRun) {
            $this->warn('DRY RUN: tidak ada yang diubah.');

            return self::SUCCESS;
        }

        $this->warn('Pastikan sync stok sistem lama untuk toko ini SUDAH dimatikan. Dua sistem yang sama-sama menulis akan membuat angka di marketplace berosilasi, dan yang menang adalah yang menulis terakhir — bukan yang benar.');

        if (! $this->option('force') && ! $this->confirm('Sync stok sistem lama untuk toko ini sudah dimatikan, dan push stok boleh diaktifkan sekarang?')) {
            $this->info('Dibatalkan.');

            return self::SUCCESS;
        }

        $shop->forceFill([
            'stock_push_enabled' => true,
            'stock_push_buffer'  => $buffer,
            'stock_handover_at'  => now(),
        ])->save();

        ResyncShopStockJob::dispatch($shop->id);

        $this->info("Push stok aktif untuk {$shop->shop_name}. Resync awal sudah diantrikan.");

        if ($buffer > 0) {
            $this->line("Buffer {$buffer} unit aktif. Lepas dengan: php artisan channel:stock-handover --shop={$shopId} --buffer=0 --force");
        }

        $this->line("Rollback kalau ada masalah: php artisan channel:stock-rollback --shop={$shopId}");

        return self::SUCCESS;
    }
}
