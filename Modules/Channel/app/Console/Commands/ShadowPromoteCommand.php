<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;

class ShadowPromoteCommand extends Command
{
    protected $signature = 'channel:shadow-promote
        {--order=* : No. pesanan internal atau no. order marketplace. Bisa diulang}
        {--dry-run : Tampilkan kelayakannya tanpa mengubah apa pun}';

    protected $description = 'Promosikan order shadow tertentu jadi order sungguhan untuk gladi resik fulfillment.';

    public function handle(SalesOrderService $orderService): int
    {
        $keys = array_filter((array) $this->option('order'));

        if ($keys === []) {
            $this->error('Minimal satu --order wajib diisi. Command ini sengaja tidak bisa memproses semua order sekaligus.');

            return self::FAILURE;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $rows = [];
        $promoted = 0;

        foreach ($keys as $key) {
            $order = SalesOrder::query()
                ->onlyShadow()
                ->where(fn ($query) => $query->where('salesorder_no', $key)->orWhere('channel_order_no', $key))
                ->first();

            if (! $order) {
                $rows[] = [$key, '-', 'tidak ditemukan sebagai order shadow'];
                continue;
            }

            if (! SalesOrderService::isPromotableFromShadow($order)) {
                $rows[] = [$key, $order->status, 'dilewati: sudah diproses atau dibatalkan'];
                continue;
            }

            if ($isDryRun) {
                $rows[] = [$order->salesorder_no, $order->status, 'layak dipromosikan'];
                continue;
            }

            if ($orderService->promoteFromShadow($order)) {
                $promoted++;
                $rows[] = [$order->salesorder_no, $order->status, 'dipromosikan'];
            } else {
                $rows[] = [$order->salesorder_no, $order->status, 'gagal dipromosikan'];
            }
        }

        $this->table(['Order', 'Status', 'Hasil'], $rows);

        if ($isDryRun) {
            $this->warn('DRY RUN: tidak ada yang diubah.');

            return self::SUCCESS;
        }

        if ($promoted > 0) {
            $this->info("{$promoted} order dipromosikan.");
            $this->warn('Tandai order ini selesai di sistem lama supaya AWB tidak diterbitkan dua kali, dan sesuaikan selisih stoknya.');
        }

        return self::SUCCESS;
    }
}
