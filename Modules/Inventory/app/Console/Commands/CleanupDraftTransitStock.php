<?php

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Services\InventoryService;

/**
 * Bersihkan stok yang menggantung di lokasi transit akibat perilaku lama
 * (sebelum 24c0f14b): item transfer DRAFT langsung menambah on_hand transit,
 * padahal barangnya belum dikirim.
 *
 * Kode baru hanya menghentikan kasus baru -- baris lama tidak tertarik sendiri,
 * karena jalur release yang sekarang memang tidak lagi menyentuh transit.
 *
 * Yang DITARIK hanya sisi transit. Sisi sumber sengaja tidak disentuh: perilaku
 * lama tidak pernah mengurangi on_hand rak asal, jadi stok fisiknya sudah benar.
 */
class CleanupDraftTransitStock extends Command
{
    protected $signature = 'inventory:cleanup-draft-transit
                            {--apply : Tulis perubahan. Tanpa flag ini hanya melaporkan (dry-run).}';

    protected $description = 'Tarik kembali stok transit milik transfer yang masih DRAFT/APPROVED (warisan perilaku lama).';

    /** Status yang barangnya BELUM berjalan, jadi tidak boleh ada di transit. */
    private const STATUS_BELUM_JALAN = ['DRAFT', 'APPROVED'];

    public function handle(
        InventoryRepository $inventoryRepository,
        InventoryMovementRepository $movementRepository,
        InventoryService $inventoryService,
    ): int {
        $gantung = DB::table('inventory_transfers as t')
            ->join('inventory_transfer_items as ti', 'ti.inventory_transfer_id', '=', 't.id')
            ->join('inventory_movements as m', function ($j) {
                $j->on('m.transaction_number', '=', 't.transfer_number')
                    ->on('m.item_id', '=', 'ti.item_id');
            })
            ->whereIn('t.status', self::STATUS_BELUM_JALAN)
            ->whereIn('m.source', ['TRANSIT_IN', 'TRANSIT_OUT'])
            ->groupBy('t.transfer_number', 't.status', 'ti.item_id', 'ti.batch_no', 'ti.serial_no')
            ->havingRaw('SUM(m.qty) > 0')
            ->selectRaw('t.transfer_number, t.status, ti.item_id, ti.batch_no, ti.serial_no, SUM(m.qty) AS qty')
            ->get();

        if ($gantung->isEmpty()) {
            $this->info('Tidak ada stok transit yang menggantung.');

            return self::SUCCESS;
        }

        [$transitLocationId, $transitBinId] = $inventoryService->resolveTransitLocation();

        $total = 0;
        foreach ($gantung as $row) {
            $sku = DB::table('product_variants')->where('id', $row->item_id)->value('sku');
            $this->line(sprintf('  %-18s %-9s %-32s %d unit', $row->transfer_number, $row->status, $sku, $row->qty));
            $total += (int) $row->qty;
        }

        $transitTotal = (int) DB::table('inventories')
            ->where('location_id', $transitLocationId)
            ->sum('on_hand');

        $this->line(sprintf("\n  transit sekarang   : %d", $transitTotal));
        $this->line(sprintf('  akan ditarik       : %d', $total));
        $this->line(sprintf('  transit seharusnya : %d', $transitTotal - $total));

        if (! $this->option('apply')) {
            $this->warn("\nDry-run. Jalankan ulang dengan --apply untuk menarik {$total} unit.");

            return self::SUCCESS;
        }

        $ditarik = 0;

        foreach ($gantung as $row) {
            DB::transaction(function () use ($row, $transitLocationId, $transitBinId, $inventoryRepository, $movementRepository, &$ditarik) {
                $transit = $inventoryRepository->findExactForUpdate(
                    $row->item_id,
                    $transitLocationId,
                    $transitBinId,
                    $row->batch_no ?? '',
                    $row->serial_no ?? '',
                );

                if (! $transit) {
                    return;
                }

                // Tidak boleh menarik lebih dari yang benar-benar ada di transit:
                // sebagian bisa sudah tertarik jalur lain, dan on_hand transit
                // tidak boleh jadi negatif.
                $take = min((int) $row->qty, (int) $transit->on_hand);
                if ($take <= 0) {
                    return;
                }

                $transit->on_hand -= $take;
                $inventoryRepository->updateStock($transit);

                $movementRepository->create([
                    'item_id'            => $row->item_id,
                    'location_id'        => $transitLocationId,
                    'bin_id'             => $transitBinId,
                    'transaction_number' => $row->transfer_number,
                    'source'             => 'TRANSIT_OUT',
                    'qty'                => -$take,
                    'balance'            => $transit->on_hand,
                    'transaction_date'   => now(),
                    'created_by'         => 'system',
                ]);

                $ditarik += $take;
            });
        }

        $this->info("Selesai. {$ditarik} unit ditarik dari transit.");

        return self::SUCCESS;
    }
}
