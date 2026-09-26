<?php

declare(strict_types=1);

namespace Modules\Inventory\Repositories;

use App\Support\WarehouseAccess;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Models\SalesOrder;

final class OrderRecoveryRepository
{
    public function findOrder(string $reference, ?string $channel = null, ?string $shopId = null): ?SalesOrder
    {
        $query = SalesOrder::query()
            ->where(function ($query) use ($reference): void {
                $query->where('salesorder_no', $reference)
                    ->orWhere('channel_order_no', $reference);
            });

        if ($channel !== null && $channel !== '') {
            $query->where('source', strtolower($channel));
        }
        if ($shopId !== null && $shopId !== '') {
            $query->where('channel_shop_id', $shopId);
        }

        WarehouseAccess::apply($query, 'location_id');

        return $query->latest('updated_at')->first();
    }

    public function latestWebhookIdentity(string $reference): ?array
    {
        $query = DB::table('channel_webhook_inbox as wi')
            ->join('channel_shops as cs', function ($join): void {
                $join->on('cs.shop_id', '=', 'wi.shop_id')
                    ->whereNull('cs.disconnected_at')
                    ->where('cs.is_active', true)
                    ->where('cs.order_sync_enabled', true);
            })
            ->where('wi.order_reference', $reference)
            ->whereIn('wi.channel', ['shopee', 'tiktok', 'lazada', 'woocommerce'])
            ->orderByDesc('wi.received_at');

        WarehouseAccess::apply($query, 'cs.stock_source_location_id');

        $row = $query->first(['wi.channel', 'wi.shop_id']);
        if ($row === null || empty($row->channel) || empty($row->shop_id)) {
            return null;
        }

        return [
            'channel' => strtolower((string) $row->channel),
            'shop_id' => (string) $row->shop_id,
        ];
    }

    public function batchRecoverySlice(string $batchId, int $limit): ?array
    {
        $members = SalesOrder::query()
            ->join('bulk_shipping_label_items as batch_item', 'batch_item.order_id', '=', 'sales_orders.id')
            ->where('batch_item.batch_id', $batchId)
            ->where('batch_item.status', '!=', BulkShippingLabelItem::STATUS_SKIPPED_INSTANT);
        WarehouseAccess::apply($members, 'sales_orders.location_id');

        $accessibleTotal = (clone $members)->count();
        if ($accessibleTotal === 0) {
            return null;
        }

        $batch = DB::table('bulk_shipping_label_batches')
            ->where('id', $batchId)
            ->first([
                'id',
                'status',
                'total_count',
                'done_count',
                'failed_count',
                'created_at',
            ]);

        if ($batch === null) {
            return null;
        }

        $accessibleDone = (clone $members)
            ->whereIn('batch_item.status', BulkShippingLabelItem::COMPLETED_STATUSES)
            ->count();
        $accessibleFailed = (clone $members)
            ->where('batch_item.status', BulkShippingLabelItem::STATUS_FAILED)
            ->count();

        $unresolved = (clone $members)
            ->where(function ($query): void {
                $query->whereNotIn('batch_item.status', BulkShippingLabelItem::COMPLETED_STATUSES)
                    ->orWhereNull('sales_orders.tracking_number')
                    ->orWhere('sales_orders.tracking_number', '')
                    ->orWhereNull('sales_orders.shipping_label_status')
                    ->orWhere('sales_orders.shipping_label_status', '!=', 'ready');
            });
        $remaining = (clone $unresolved)->count();
        $items = $unresolved
            ->orderBy('batch_item.created_at')
            ->limit($limit)
            ->get([
                'sales_orders.channel_order_no',
                'sales_orders.salesorder_no',
                'sales_orders.source',
                'sales_orders.channel_shop_id',
            ])
            ->map(static fn (SalesOrder $order): array => [
                'reference' => (string) ($order->channel_order_no ?: $order->salesorder_no),
                'channel' => filled($order->source) ? (string) $order->source : null,
                'shop_id' => filled($order->channel_shop_id) ? (string) $order->channel_shop_id : null,
            ])
            ->all();

        return [
            'batch' => [
                'id' => (string) $batch->id,
                'status' => (string) $batch->status,
                'total' => $accessibleTotal,
                'done' => $accessibleDone,
                'failed' => $accessibleFailed,
                'accessible_total' => $accessibleTotal,
                'created_at' => $batch->created_at !== null ? (string) $batch->created_at : null,
            ],
            'remaining' => $remaining,
            'items' => $items,
        ];
    }
}
