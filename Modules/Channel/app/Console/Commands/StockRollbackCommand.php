<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Models\ChannelShop;

/**
 * Kembalikan kepemilikan stok satu toko ke sistem lama.
 *
 * Ini command darurat, jadi sengaja tanpa konfirmasi: arahnya aman (berhenti
 * menulis), dan yang mahal justru menundanya. Sengaja dipisah dari
 * channel:stock-handover supaya di situasi tertekan namanya gampang diingat.
 */
class StockRollbackCommand extends Command
{
    protected $signature = 'channel:stock-rollback
        {--shop= : shop_id marketplace. Kosongkan untuk semua toko sekaligus}';

    protected $description = 'Hentikan push stok ke marketplace (kembalikan kepemilikan stok ke sistem lama).';

    public function handle(): int
    {
        $query = ChannelShop::query()
            ->where('stock_push_enabled', true)
            ->when($this->option('shop'), fn ($q, $shopId) => $q->where('shop_id', $shopId));

        $shops = $query->get();

        if ($shops->isEmpty()) {
            $this->info('Tidak ada toko dengan push stok aktif.');

            return self::SUCCESS;
        }

        foreach ($shops as $shop) {
            $shop->forceFill(['stock_push_enabled' => false])->save();
            $this->warn("Push stok DIHENTIKAN untuk {$shop->shop_name} ({$shop->shop_id}).");
        }

        $this->newLine();
        $this->error('Langkah berikutnya yang harus dilakukan manual: nyalakan kembali sync stok sistem lama untuk toko di atas, supaya marketplace tetap punya satu penulis stok.');

        return self::SUCCESS;
    }
}
