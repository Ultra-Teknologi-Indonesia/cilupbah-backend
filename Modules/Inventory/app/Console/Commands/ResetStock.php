<?php

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockAdjustmentItem;
use Modules\Warehouse\Models\Location;

class ResetStock extends Command
{
    protected $signature = 'inventory:reset-stock
        {--location= : Kode lokasi yang akan direset (misal O, WH-PUSAT, atau ALL)}
        {--purge-history : Hapus seluruh riwayat mutasi stok, penyesuaian stok, dan reservasi terkait}
        {--dry-run : Simulasi tanpa mengubah database}
        {--force : Lewati konfirmasi keamanan}';

    protected $description = 'Darurat / Rollback: Reset seluruh angka stok ke 0 dan opsi hapus riwayat mutasi.';

    public function handle(): int
    {
        $locationCode = (string) ($this->option('location') ?? '');
        $purgeHistory = (bool) $this->option('purge-history');
        $isDryRun = (bool) $this->option('dry-run');
        $isForce = (bool) $this->option('force');

        if (empty($locationCode)) {
            $this->error('Parameter --location wajib diisi (contoh: --location=O, --location=WH-PUSAT, atau --location=ALL).');
            return self::FAILURE;
        }

        $locations = collect();
        if (strtoupper($locationCode) === 'ALL') {
            $locations = Location::all();
        } else {
            $loc = Location::where('location_code', $locationCode)->first();
            if (! $loc) {
                $this->error("Lokasi gudang dengan kode '{$locationCode}' tidak ditemukan.");
                return self::FAILURE;
            }
            $locations->push($loc);
        }

        $locationIds = $locations->pluck('id')->all();
        $locationNames = $locations->pluck('location_name')->implode(', ');

        $this->line('===============================================================');
        $this->line('  RESET STOK & PURGE RIWAYAT INVENTORI (EMERGENCY ROLLBACK)');
        $this->line('===============================================================');
        $this->line("Mode           : " . ($isDryRun ? '<fg=yellow;options=bold>DRY-RUN (SIMULASI AMAN)</>' : '<fg=red;options=bold>EKSEKUSI NYATA (DATABASE MUTATION)</>'));
        $this->line("Target Gudang  : {$locationNames} ({$locationCode})");
        $this->line("Purge History  : " . ($purgeHistory ? '<fg=red>YA (Hapus mutasi & dokumen penyesuaian)</>' : '<fg=green>TIDAK (Hanya nolkan saldo on_hand)</>'));
        $this->newLine();

        $inventoryCount = Inventory::whereIn('location_id', $locationIds)->count();
        $movementCount = InventoryMovement::whereIn('location_id', $locationIds)->count();
        $adjCount = StockAdjustment::whereIn('location_id', $locationIds)->count();
        $reservedCount = DB::table('reserved_stocks')->whereIn('location_id', $locationIds)->count();

        $this->table(['Entitas', 'Jumlah Baris Terdeteksi'], [
            ['Inventories (Rak x SKU)', number_format($inventoryCount)],
            ['Inventory Movements (Ledger)', number_format($movementCount)],
            ['Stock Adjustments (Dokumen)', number_format($adjCount)],
            ['Reserved Stocks (Komitmen)', number_format($reservedCount)],
        ]);

        if (! $isDryRun && ! $isForce) {
            if (! $this->confirm("PERINGATAN: Aksi ini akan me-reset stok pada gudang [{$locationNames}]. Apakah Anda yakin ingin melanjutkan?", false)) {
                $this->info('Operasi dibatalkan.');
                return self::SUCCESS;
            }
        }

        if ($isDryRun) {
            $this->info('🔍 [DRY-RUN] Simulasi selesai. Tidak ada data yang diubah di database.');
            return self::SUCCESS;
        }

        $this->info('Memulai proses reset di database...');

        DB::transaction(function () use ($locationIds, $purgeHistory) {

            if ($purgeHistory) {

                DB::table('inventories')->whereIn('location_id', $locationIds)->delete();
            } else {
                DB::table('inventories')->whereIn('location_id', $locationIds)->update([
                    'on_hand' => 0,
                    'on_order' => 0,
                    'available' => 0,
                    'avg_cost' => 0,
                    'updated_at' => now(),
                ]);
            }

            if ($purgeHistory) {

                $adjIds = StockAdjustment::whereIn('location_id', $locationIds)->pluck('id')->all();
                if (! empty($adjIds)) {
                    StockAdjustmentItem::whereIn('stock_adjustment_id', $adjIds)->delete();
                    StockAdjustment::whereIn('id', $adjIds)->delete();
                }

                $reservedIds = DB::table('reserved_stocks')->whereIn('location_id', $locationIds)->pluck('id')->all();
                if (! empty($reservedIds)) {
                    DB::table('reserved_stock_items')->whereIn('reserved_stock_id', $reservedIds)->delete();
                    DB::table('reserved_stocks')->whereIn('id', $reservedIds)->delete();
                }

                InventoryMovement::whereIn('location_id', $locationIds)->delete();
            }
        });

        $this->info('✅ Berhasil me-reset stok dan membersihkan riwayat inventori.');
        return self::SUCCESS;
    }
}
