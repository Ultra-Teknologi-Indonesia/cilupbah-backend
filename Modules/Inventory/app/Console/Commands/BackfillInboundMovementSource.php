<?php

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Perbaiki label historis penerimaan barang di kronologi stok.
 *
 * InboundService::movementSourceFor() dulu meruntuhkan PURCHASE_ORDER, TRANSIT_IN,
 * dan CONSIGNMENT jadi 'ADJUSTMENT'. Kode sudah diperbaiki; command ini merapikan
 * baris lama.
 *
 * BAHAYA yang dihindari: 'ADJUSTMENT' JUGA ditulis sah oleh ProcessStockAdjustmentJob
 * untuk penyesuaian stok betulan. Backfill by-source akan merusaknya. Karena itu
 * pemilihan barisnya WAJIB lewat join ke `inbounds` pada transaction_number.
 *
 * Aman dijalankan berulang: baris yang sudah berlabel benar tidak lagi cocok
 * dengan filter `source = 'ADJUSTMENT'`.
 */
class BackfillInboundMovementSource extends Command
{
    protected $signature = 'inventory:backfill-inbound-source
                            {--apply : Tulis perubahan. Tanpa flag ini command hanya melaporkan (dry-run).}';

    protected $description = 'Ubah source movement penerimaan lama dari ADJUSTMENT ke PURCHASE/TRANSFER_IN/CONSIGNMENT sesuai tipe inbound-nya.';

    private const TYPE_TO_SOURCE = [
        'PURCHASE_ORDER' => 'PURCHASE',
        'TRANSIT_IN'     => 'TRANSFER_IN',
        'CONSIGNMENT'    => 'CONSIGNMENT',
    ];

    public function handle(): int
    {
        $collisions = DB::table('inbounds as i')
            ->join('stock_adjustments as sa', 'sa.adjustment_no', '=', 'i.transaction_number')
            ->count();

        if ($collisions > 0) {
            $this->error("Ditemukan {$collisions} tabrakan penomoran antara inbounds dan stock_adjustments.");
            $this->error('Backfill DIBATALKAN: join transaction_number tidak lagi bisa dipercaya sebagai diskriminator.');

            return self::FAILURE;
        }

        $rencana = DB::table('inventory_movements as m')
            ->join('inbounds as i', 'i.transaction_number', '=', 'm.transaction_number')
            ->where('m.source', 'ADJUSTMENT')
            ->whereIn('i.type', array_keys(self::TYPE_TO_SOURCE))
            ->select('i.type', DB::raw('COUNT(*) as jml'))
            ->groupBy('i.type')
            ->pluck('jml', 'type');

        if ($rencana->isEmpty()) {
            $this->info('Tidak ada baris yang perlu di-backfill.');

            return self::SUCCESS;
        }

        $total = 0;
        foreach ($rencana as $type => $jml) {
            $this->line(sprintf('  %-16s -> %-12s : %d baris', $type, self::TYPE_TO_SOURCE[$type], $jml));
            $total += $jml;
        }

        $sisaAdjustment = DB::table('inventory_movements')->where('source', 'ADJUSTMENT')->count() - $total;
        $this->line(sprintf('  %-16s    %-12s : %d baris (penyesuaian stok asli, TIDAK disentuh)', 'ADJUSTMENT', 'tetap', $sisaAdjustment));

        if (! $this->option('apply')) {
            $this->warn("\nDry-run. Jalankan ulang dengan --apply untuk menulis {$total} perubahan.");

            return self::SUCCESS;
        }

        $diubah = 0;
        DB::transaction(function () use (&$diubah) {
            foreach (self::TYPE_TO_SOURCE as $type => $source) {
                $diubah += DB::table('inventory_movements')
                    ->whereIn('transaction_number', function ($q) use ($type) {
                        $q->select('transaction_number')->from('inbounds')->where('type', $type);
                    })
                    ->where('source', 'ADJUSTMENT')
                    ->update(['source' => $source]);
            }
        });

        $this->info("Selesai. {$diubah} baris diperbarui.");

        return self::SUCCESS;
    }
}
