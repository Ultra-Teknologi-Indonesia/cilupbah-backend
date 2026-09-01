<?php

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileTransferOnOrder extends Command
{
    protected $signature = 'inventory:reconcile-transfer-on-order
        {--sku= : Filter berdasarkan SKU}
        {--transfer= : Filter berdasarkan nomor transfer}
        {--fix : Hapus legacy transfer lock dari inventories.on_order}';

    protected $description = 'Audit dan bersihkan legacy transfer reservation yang masih tersimpan di on_order.';

    public function handle(): int
    {
        $plans = $this->buildPlans();
        $rows = [];
        $evaluatedPlans = [];

        foreach ($plans as $plan) {
            $inventory = $this->findInventory($plan);
            $currentOnOrder = (int) ($inventory->on_order ?? 0);
            $currentOnHand = (int) ($inventory->on_hand ?? 0);
            $isInbound = (bool) ($inventory->is_inbound ?? false);

            if (! $inventory) {
                $status = 'MISSING_INVENTORY';
                $releaseQty = 0;
                $projectedOnOrder = null;
                $projectedAvailable = null;
            } elseif ($currentOnOrder === 0) {
                $status = 'ALREADY_CLEAN';
                $releaseQty = 0;
                $projectedOnOrder = 0;
                $projectedAvailable = $isInbound ? 0 : $currentOnHand;
            } elseif ($currentOnOrder < $plan['transfer_qty']) {
                $status = 'UNDER / SKIP';
                $releaseQty = 0;
                $projectedOnOrder = $currentOnOrder;
                $projectedAvailable = $isInbound ? 0 : $currentOnHand - $projectedOnOrder;
            } else {
                $status = 'READY';
                $releaseQty = $plan['transfer_qty'];
                $projectedOnOrder = $currentOnOrder - $releaseQty;
                $projectedAvailable = $isInbound ? 0 : $currentOnHand - $projectedOnOrder;
            }

            $plan['inventory_id'] = $inventory->id ?? null;
            $plan['current_on_order'] = $currentOnOrder;
            $plan['current_on_hand'] = $currentOnHand;
            $plan['release_qty'] = $releaseQty;
            $plan['projected_on_order'] = $projectedOnOrder;
            $plan['projected_available'] = $projectedAvailable;
            $plan['status'] = $status;
            $plan['bin_code'] = $inventory->bin_code ?? $plan['bin_code'];
            $plan['is_inbound'] = $isInbound;
            $evaluatedPlans[] = $plan;

            $rows[] = [
                'SKU' => $plan['sku'],
                'Transfer' => implode(', ', $plan['transfer_numbers']),
                'Lokasi Asal' => $plan['location_code'],
                'Rak' => $plan['bin_code'] ?? '—',
                'Transfer Qty' => $plan['transfer_qty'],
                'On Order Saat Ini' => $currentOnOrder,
                'Dilepas' => $releaseQty,
                'On Order Setelahnya' => $projectedOnOrder ?? '—',
                'Available Setelahnya' => $projectedAvailable ?? '—',
                'Status' => $status,
            ];
        }

        $this->line('==========================================================================');
        $this->line('  AUDIT LEGACY TRANSFER RESERVATION DI ON_ORDER');
        $this->line('==========================================================================');
        $this->line('Mode: '.($this->option('fix') ? 'FIX' : 'DRY-RUN / INSPECTION ONLY (AMAN)'));
        $this->line('Aturan target: on_order hanya berasal dari sales order.');
        $this->newLine();

        if (empty($rows)) {
            $this->info('Tidak ditemukan transfer aktif yang masih berpotensi memakai legacy on_order.');

            return self::SUCCESS;
        }

        $this->table([
            'SKU',
            'Transfer',
            'Lokasi Asal',
            'Rak',
            'Transfer Qty',
            'On Order Saat Ini',
            'Dilepas',
            'On Order Setelahnya',
            'Available Setelahnya',
            'Status',
        ], $rows);

        $readyPlans = array_values(array_filter($evaluatedPlans, fn (array $plan): bool => $plan['status'] === 'READY'));

        if (! $this->option('fix')) {
            $this->newLine();
            $this->line(sprintf('%d baris siap dibersihkan.', count($readyPlans)));
            $this->line('Jalankan ulang dengan --fix setelah meninjau output.');

            return self::SUCCESS;
        }

        $fixed = 0;
        $skipped = 0;

        foreach ($readyPlans as $plan) {
            $updated = DB::transaction(function () use ($plan): bool {
                $inventory = DB::table('inventories as i')
                    ->join('location_bins as b', 'b.id', '=', 'i.bin_id')
                    ->where('i.id', $plan['inventory_id'])
                    ->lockForUpdate()
                    ->select('i.id', 'i.on_hand', 'i.on_order', 'b.is_inbound')
                    ->first();

                if (! $inventory || (int) $inventory->on_order < $plan['transfer_qty']) {
                    return false;
                }

                $onOrder = (int) $inventory->on_order - $plan['transfer_qty'];
                $available = (bool) $inventory->is_inbound
                    ? 0
                    : (int) $inventory->on_hand - $onOrder;

                DB::table('inventories')
                    ->where('id', $inventory->id)
                    ->update([
                        'on_order' => $onOrder,
                        'available' => $available,
                        'updated_at' => now(),
                    ]);

                return true;
            });

            if ($updated) {
                $fixed++;
            } else {
                $skipped++;
            }
        }

        $this->info(sprintf('%d baris inventory dibersihkan.', $fixed));
        if ($skipped > 0) {
            $this->warn(sprintf('%d baris dilewati karena kondisi berubah atau on_order tidak mencukupi.', $skipped));
        }

        return self::SUCCESS;
    }

    /**
     * Group active synced transfer items by their exact inventory row.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildPlans(): array
    {
        $query = DB::table('inventory_transfer_items as ti')
            ->join('inventory_transfers as t', 't.id', '=', 'ti.inventory_transfer_id')
            ->join('product_variants as pv', 'pv.id', '=', 'ti.item_id')
            ->join('locations as l', 'l.id', '=', 't.source_location_id')
            ->leftJoin('location_bins as b', 'b.id', '=', 'ti.source_bin_id')
            ->whereIn('t.status', ['DRAFT', 'APPROVED'])
            ->where('ti.sync_status', 'SYNCED')
            ->whereNotNull('ti.source_bin_id')
            ->select(
                'ti.item_id',
                'ti.source_bin_id',
                'ti.batch_no',
                'ti.serial_no',
                'ti.qty',
                'pv.sku',
                't.id as transfer_id',
                't.transfer_number',
                't.status as transfer_status',
                'l.id as location_id',
                'l.location_code',
                'b.bin_final_code'
            )
            ->orderBy('t.transfer_number');

        $sku = trim((string) ($this->option('sku') ?? ''));
        if ($sku !== '') {
            $query->whereRaw('UPPER(pv.sku) LIKE ?', ['%'.strtoupper($sku).'%']);
        }

        $transfer = trim((string) ($this->option('transfer') ?? ''));
        if ($transfer !== '') {
            $query->where('t.transfer_number', $transfer);
        }

        $plans = [];
        foreach ($query->get() as $row) {
            $batchNo = (string) ($row->batch_no ?? '');
            $serialNo = (string) ($row->serial_no ?? '');
            $key = implode('|', [
                $row->item_id,
                $row->location_id,
                $row->source_bin_id,
                $batchNo,
                $serialNo,
            ]);

            if (! isset($plans[$key])) {
                $plans[$key] = [
                    'item_id' => $row->item_id,
                    'location_id' => $row->location_id,
                    'source_bin_id' => $row->source_bin_id,
                    'batch_no' => $batchNo,
                    'serial_no' => $serialNo,
                    'sku' => $row->sku,
                    'location_code' => $row->location_code,
                    'bin_code' => $row->bin_final_code,
                    'transfer_qty' => 0,
                    'transfer_numbers' => [],
                    'transfer_ids' => [],
                ];
            }

            $plans[$key]['transfer_qty'] += (int) $row->qty;
            $plans[$key]['transfer_numbers'][] = $row->transfer_number;
            $plans[$key]['transfer_ids'][] = $row->transfer_id;
        }

        return $plans;
    }

    private function findInventory(array $plan): ?object
    {
        return DB::table('inventories as i')
            ->leftJoin('location_bins as b', 'b.id', '=', 'i.bin_id')
            ->where('i.item_id', $plan['item_id'])
            ->where('i.location_id', $plan['location_id'])
            ->where('i.bin_id', $plan['source_bin_id'])
            ->where('i.batch_no', $plan['batch_no'])
            ->where('i.serial_no', $plan['serial_no'])
            ->select('i.id', 'i.on_hand', 'i.on_order', 'b.is_inbound', 'b.bin_final_code as bin_code')
            ->first();
    }
}
