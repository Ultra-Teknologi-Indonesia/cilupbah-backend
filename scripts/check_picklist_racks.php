<?php

/*
|--------------------------------------------------------------------------
| Diagnosa "Rak belum diketahui untuk SKU ini"
|--------------------------------------------------------------------------
| Jalankan di production:
|
|   php artisan tinker < scripts/check_picklist_racks.php
|
| Untuk picklist lain, ganti $PICKLIST_NO di bawah.
|
| Script ini MENIRU persis logika mobile (_fetchItemBins + _primaryBinInfo):
| sebuah item dianggap "rak diketahui" HANYA jika ada baris inventory yang
|   (a) di location picklist, (b) placed (bin final, non-inbound), (c) on_hand > 0.
| Lalu ia jelaskan kenapa gagal: belum putaway, stok kosong, atau cuma
| SkuRackAssignment (on_hand = 0).
*/

use Modules\Outbound\Models\Picklist;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\SkuRackAssignment;

$PICKLIST_NO = 'PICK-000000022';

$pl = Picklist::with(['items', 'location:id,location_name'])
    ->where('picklist_no', $PICKLIST_NO)
    ->first();

if (! $pl) {
    echo "Picklist {$PICKLIST_NO} TIDAK DITEMUKAN.\n";
    return;
}

$locId = $pl->location_id;
echo str_repeat('=', 78) . "\n";
echo "Picklist : {$pl->picklist_no}  (status: {$pl->status})\n";
echo "Location : {$pl->location->location_name} [{$locId}]\n";
echo "Items    : " . $pl->items->count() . "\n";
echo str_repeat('=', 78) . "\n\n";

foreach ($pl->items as $item) {
    echo "SKU {$item->sku}  (item_id: {$item->item_id})  ordered={$item->qty_ordered} picked={$item->qty_picked}\n";

    // Semua baris inventory item ini DI LOKASI PICKLIST (apa pun statusnya).
    $rows = Inventory::where('item_id', $item->item_id)
        ->where('location_id', $locId)
        ->with(['bin:id,bin_final_code,is_inbound'])
        ->get();

    // Ini yang dianggap "rak valid" oleh mobile: placed + on_hand > 0.
    $placed = $rows->filter(function ($inv) {
        return $inv->bin && ! $inv->bin->is_inbound && (int) $inv->on_hand > 0;
    });

    // Stok yang ADA tapi belum ditempatkan ke rak final (belum putaway).
    $pending = $rows->filter(function ($inv) {
        return (int) $inv->on_hand > 0 && (! $inv->bin || $inv->bin->is_inbound);
    });

    $totalOnHand = (int) $rows->sum('on_hand');

    // Rack assignment (mapping SKU->rak) di lokasi ini, kalau ada.
    $assign = SkuRackAssignment::where('item_id', $item->item_id)
        ->where('location_id', $locId)
        ->with('bin:id,bin_final_code')
        ->get();

    if ($placed->isNotEmpty()) {
        $binList = $placed->map(fn ($i) => "{$i->bin->bin_final_code}(on_hand={$i->on_hand})")->implode(', ');
        echo "  ✅ RAK DIKETAHUI  -> {$binList}\n";
    } else {
        echo "  ❌ RAK BELUM DIKETAHUI (mobile akan tampil 'Hubungi admin')\n";
        echo "     total_on_hand di lokasi ini = {$totalOnHand}\n";

        if ($pending->isNotEmpty()) {
            $pList = $pending->map(function ($i) {
                $where = $i->bin ? "bin inbound {$i->bin->bin_final_code}" : 'TANPA bin (floating)';
                return "on_hand={$i->on_hand} @ {$where}";
            })->implode('; ');
            echo "     ➜ SEBAB: stok ada tapi BELUM di-putaway -> {$pList}\n";
            echo "       Aksi: lakukan Penempatan (putaway) ke rak final.\n";
        } elseif ($assign->isNotEmpty()) {
            $aList = $assign->map(fn ($a) => optional($a->bin)->bin_final_code ?? $a->bin_id)->implode(', ');
            echo "     ➜ SEBAB: rak terpetakan (SkuRackAssignment: {$aList}) tapi on_hand = 0.\n";
            echo "       Aksi: replenishment / cek kenapa stok fisik 0.\n";
        } else {
            // cek apakah stok justru ada di LOKASI LAIN
            $elsewhere = Inventory::where('item_id', $item->item_id)
                ->where('location_id', '!=', $locId)
                ->where('on_hand', '>', 0)
                ->with('location:id,location_name')
                ->get();
            if ($elsewhere->isNotEmpty()) {
                $eList = $elsewhere->map(fn ($i) => optional($i->location)->location_name . "(on_hand={$i->on_hand})")->implode(', ');
                echo "     ➜ SEBAB: stok TIDAK ada di gudang ini, tapi ada di lokasi lain -> {$eList}\n";
                echo "       Aksi: transfer stok ke {$pl->location->location_name}, atau picklist salah lokasi.\n";
            } else {
                echo "     ➜ SEBAB: stok kosong total. Butuh replenishment.\n";
            }
        }
    }
    echo "\n";
}

echo str_repeat('=', 78) . "\n";
echo "Selesai.\n";
