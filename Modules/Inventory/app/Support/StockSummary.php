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

    public static function transitForItems(array $itemIds): array
    {
        return self::transitByItem(array_values(array_filter(array_unique($itemIds))));
    }

    public static function pickedNotPackedForItems(array $itemIds): array
    {
        $itemIds = array_values(array_filter(array_unique($itemIds)));

        if (empty($itemIds)) {
            return [];
        }

        $packedSub = DB::table('packlist_items')
            ->groupBy('order_item_id')
            ->select('order_item_id', DB::raw('COALESCE(SUM(qty_packed), 0) as packed'));

        $perOrderItem = DB::table('picklist_items as pi')
            ->join('picklist_item_allocations as pia', 'pia.picklist_item_id', '=', 'pi.id')
            ->leftJoinSub($packedSub, 'pk', 'pk.order_item_id', '=', 'pi.order_item_id')
            ->whereIn('pi.item_id', $itemIds)
            ->groupBy('pi.item_id', 'pi.order_item_id', 'pk.packed')
            ->selectRaw('pi.item_id AS item_id')
            ->selectRaw('pi.order_item_id AS order_item_id')
            ->selectRaw('COALESCE(SUM(pia.qty), 0) AS picked')
            ->selectRaw('COALESCE(pk.packed, 0) AS packed');

        $rows = DB::query()
            ->fromSub($perOrderItem, 't')
            ->groupBy('t.item_id')
            ->selectRaw('t.item_id AS item_id')
            ->selectRaw('COALESCE(SUM(GREATEST(t.picked - t.packed, 0)), 0) AS qty')
            ->get();

        $result = array_fill_keys($itemIds, 0);

        foreach ($rows as $row) {
            $result[$row->item_id] = (int) $row->qty;
        }

        return $result;
    }

    public static function pickedNotPackedByBin(array $itemIds): array
    {
        $itemIds = array_values(array_filter(array_unique($itemIds)));

        if (empty($itemIds)) {
            return [];
        }

        $allocations = DB::table('picklist_item_allocations as pia')
            ->join('picklist_items as pi', 'pi.id', '=', 'pia.picklist_item_id')
            ->whereIn('pi.item_id', $itemIds)
            ->selectRaw('pi.item_id AS item_id')
            ->selectRaw('pia.bin_id AS bin_id')
            ->selectRaw('pia.qty AS qty')
            ->selectRaw(
                'SUM(pia.qty) OVER (PARTITION BY pi.order_item_id '.
                'ORDER BY pia.picked_at, pia.id) AS running'
            )
            ->selectRaw(
                '(SELECT COALESCE(SUM(pk.qty_packed), 0) FROM packlist_items pk '.
                'WHERE pk.order_item_id = pi.order_item_id) AS packed'
            );

        $rows = DB::query()
            ->fromSub($allocations, 'a')
            ->groupBy('a.item_id', 'a.bin_id')
            ->selectRaw('a.item_id AS item_id')
            ->selectRaw('a.bin_id AS bin_id')
            ->selectRaw(
                'COALESCE(SUM(LEAST(a.qty, GREATEST(a.running - a.packed, 0))), 0) AS qty'
            )
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[$row->item_id][$row->bin_id] = (int) $row->qty;
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

            ->selectRaw('ii.item_id, COALESCE(SUM(GREATEST(ii.received_qty - ii.putaway_qty, 0)),0) AS qty')
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
