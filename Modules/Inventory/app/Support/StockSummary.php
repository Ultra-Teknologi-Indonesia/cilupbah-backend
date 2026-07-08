<?php

namespace Modules\Inventory\Support;

use Illuminate\Support\Facades\DB;

/**
 * Sumber kebenaran tunggal untuk angka stok yang dilaporkan/dijual/dipick.
 *
 * Aturan: stok baru dihitung sebagai on_hand/available (sellable & pickable)
 * hanya bila SUDAH DITEMPATKAN di bin rak final (bin_id NOT NULL dan
 * location_bins.is_inbound = false). Stok yang baru diterima tapi masih di
 * Bin Inbound, atau baris agregat bin_id NULL, berstatus "menunggu penempatan"
 * dan tidak masuk on_hand/available.
 *
 * Catatan model: `reserved` disimpan di baris agregat bin_id NULL
 * (lihat StockService::reserveSingle), jadi reserved dijumlahkan lintas semua
 * baris, sedangkan on_hand yang dihitung hanya dari baris yang ditempatkan.
 */
class StockSummary
{
    /**
     * @param  array<int,string>  $itemIds
     * @param  array<int,string>|null  $locationIds  batasi ke gudang tertentu bila diisi
     * @return array<string, array{on_hand:int,pending_placement:int,reserved:int,on_order:int,available:int}>
     */
    public static function forItems(array $itemIds, ?array $locationIds = null): array
    {
        $itemIds = array_values(array_filter(array_unique($itemIds)));
        if (empty($itemIds)) {
            return [];
        }

        $query = DB::table('inventories as i')
            ->leftJoin('location_bins as b', 'b.id', '=', 'i.bin_id')
            ->whereIn('i.item_id', $itemIds)
            ->groupBy('i.item_id')
            ->selectRaw('i.item_id')
            ->selectRaw('COALESCE(SUM(CASE WHEN b.id IS NOT NULL AND b.is_inbound = false THEN i.on_hand ELSE 0 END),0) AS placed_on_hand')
            ->selectRaw('COALESCE(SUM(CASE WHEN b.id IS NULL OR b.is_inbound = true THEN i.on_hand ELSE 0 END),0) AS pending_on_hand')
            ->selectRaw('COALESCE(SUM(i.reserved),0) AS reserved')
            ->selectRaw('COALESCE(SUM(i.on_order),0) AS on_order');

        if (! empty($locationIds)) {
            $query->whereIn('i.location_id', $locationIds);
        }

        $result = [];
        foreach ($query->get() as $row) {
            $onHand = (int) $row->placed_on_hand;
            $reserved = (int) $row->reserved;

            $result[$row->item_id] = [
                'on_hand' => $onHand,
                'pending_placement' => (int) $row->pending_on_hand,
                'reserved' => $reserved,
                'on_order' => (int) $row->on_order,
                'available' => max(0, $onHand - $reserved),
            ];
        }

        return $result;
    }

    /**
     * Ringkas koleksi inventory yang SUDAH di-eager-load menjadi angka placed-only.
     * on_hand hanya dari baris yang ditempatkan (bin rak final). Agar aman: bila relasi
     * `bin` belum di-load pada suatu baris (dan bin_id-nya terisi), baris dianggap
     * "ditempatkan" supaya tidak salah menampilkan 0 — samakan dengan perilaku lama.
     *
     * @return array{on_hand:int,pending_placement:int,reserved:int,on_order:int,available:int}
     */
    public static function partitionLoaded($inventories): array
    {
        $rows = collect($inventories);

        $isPlaced = function ($inv) {
            if ($inv->bin_id === null) {
                return false;
            }
            if (! $inv->relationLoaded('bin')) {
                return true; // fallback aman: hindari 0 palsu saat bin tak di-load
            }

            return $inv->bin !== null && ! (bool) $inv->bin->is_inbound;
        };

        $placedOnHand = (int) $rows->filter($isPlaced)->sum('on_hand');
        $pending = (int) $rows->reject($isPlaced)->sum('on_hand');
        $reserved = (int) $rows->sum('reserved');

        return [
            'on_hand' => $placedOnHand,
            'pending_placement' => $pending,
            'reserved' => $reserved,
            'on_order' => (int) $rows->sum('on_order'),
            'available' => max(0, $placedOnHand - $reserved),
        ];
    }

    /**
     * Ringkasan satu item (opsional per lokasi). Selalu mengembalikan semua kunci.
     *
     * @return array{on_hand:int,pending_placement:int,reserved:int,on_order:int,available:int}
     */
    public static function forItem(string $itemId, ?string $locationId = null): array
    {
        $rows = self::forItems([$itemId], $locationId ? [$locationId] : null);

        return $rows[$itemId] ?? [
            'on_hand' => 0,
            'pending_placement' => 0,
            'reserved' => 0,
            'on_order' => 0,
            'available' => 0,
        ];
    }
}
