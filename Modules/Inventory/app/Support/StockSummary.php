<?php

namespace Modules\Inventory\Support;

use Illuminate\Support\Facades\DB;

class StockSummary
{

    public static function placedOnHandSql(string $inv = 'inventories', string $bin = 'location_bins'): string
    {
        return "COALESCE(SUM(CASE WHEN ({$bin}.id IS NULL OR {$bin}.is_inbound = false) THEN {$inv}.on_hand ELSE 0 END),0)";
    }

    public static function onOrderSql(string $inv = 'inventories'): string
    {
        return "COALESCE(SUM({$inv}.on_order),0)";
    }

    public static function availableSql(string $inv = 'inventories', string $bin = 'location_bins'): string
    {
        return 'GREATEST(0, ' . self::placedOnHandSql($inv, $bin) . ' - ' . self::onOrderSql($inv) . ')';
    }

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
            ->selectRaw('COALESCE(SUM(i.on_order),0) AS on_order');

        if (! empty($locationIds)) {
            $query->whereIn('i.location_id', $locationIds);
        }

        $transitByItem = self::transitByItem($itemIds);

        $result = [];
        foreach ($query->get() as $row) {
            $onHand = (int) $row->placed_on_hand;
            $onOrder = (int) $row->on_order;

            $result[$row->item_id] = [
                'on_hand' => $onHand,
                'pending_placement' => (int) $row->pending_on_hand,
                'on_order' => $onOrder,
                'transit' => (int) ($transitByItem[$row->item_id] ?? 0),

                'available' => max(0, $onHand - $onOrder),
            ];
        }

        return $result;
    }

    protected static function transitByItem(array $itemIds): array
    {
        if (empty($itemIds)) {
            return [];
        }

        $sysTransit = DB::table('inventories as i')
            ->join('locations as l', 'l.id', '=', 'i.location_id')
            ->whereIn('i.item_id', $itemIds)
            ->where('l.location_code', 'SYS-TRANSIT')
            ->groupBy('i.item_id')
            ->selectRaw('i.item_id, COALESCE(SUM(i.on_hand),0) AS qty')
            ->get()
            ->keyBy('item_id');

        $inboundPending = DB::table('inbound_items as ii')
            ->join('inbounds as ib', 'ib.id', '=', 'ii.inbound_id')
            ->whereIn('ii.item_id', $itemIds)
            ->where('ib.type', 'TRANSIT_IN')
            ->whereNotIn('ib.status', ['COMPLETED', 'CANCELLED'])
            ->groupBy('ii.item_id')
            ->selectRaw('ii.item_id, COALESCE(SUM(GREATEST(ii.received_qty - ii.putaway_qty - COALESCE(ii.reserved_qty, 0), 0)),0) AS qty')
            ->get()
            ->keyBy('item_id');

        $out = [];
        foreach ($itemIds as $id) {
            $a = (int) ($sysTransit[$id]->qty ?? 0);
            $b = (int) ($inboundPending[$id]->qty ?? 0);
            $out[$id] = max(0, $a + $b);
        }

        return $out;
    }

    public static function partitionLoaded($inventories): array
    {
        $rows = collect($inventories);

        $isPlaced = function ($inv) {
            if ($inv->bin_id === null) {
                return false;
            }
            if (! $inv->relationLoaded('bin')) {
                return true;
            }

            return $inv->bin !== null && ! (bool) $inv->bin->is_inbound;
        };

        $placedOnHand = (int) $rows->filter($isPlaced)->sum('on_hand');
        $pending = (int) $rows->reject($isPlaced)->sum('on_hand');
        $onOrder = (int) $rows->sum('on_order');

        return [
            'on_hand' => $placedOnHand,
            'pending_placement' => $pending,
            'on_order' => $onOrder,

            'transit' => 0,

            'available' => max(0, $placedOnHand - $onOrder),
        ];
    }

    public static function forItem(string $itemId, ?string $locationId = null): array
    {
        $rows = self::forItems([$itemId], $locationId ? [$locationId] : null);

        return $rows[$itemId] ?? [
            'on_hand' => 0,
            'pending_placement' => 0,
            'on_order' => 0,
            'transit' => 0,
            'available' => 0,
        ];
    }
}
