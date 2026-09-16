<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Channel\Services\ChannelWebhookService;
use RuntimeException;

final class OrderCutoverLookupService
{
    private const LOCATION_CODES = ['O', 'WH-PUSAT'];

    private const PROCESSED_STATUSES = [
        'picked', 'packed', 'shipped', 'completed', 'delivered', 'ready-to-ship',
    ];

    private const ORDER_CHILD_TABLES = [
        'sales_order_items', 'sales_order_fee_lines', 'sales_order_status_histories',
        'order_buyer_confirmations', 'order_bin_allocations', 'sales_invoices',
        'channel_settlement_adjustments', 'sales_returns', 'warranties',
        'bulk_shipping_label_items', 'shipment_orders', 'picklist_items',
        'packlists', 'fulfillment_removals',
    ];

    public function __construct(
        private readonly ChannelWebhookService $webhookService,
    ) {}

    public function lookup(string $reference): array
    {
        $reference = $this->normalizeReference($reference);
        $select = [
            'so.id', 'so.salesorder_no', 'so.channel_order_no', 'so.source',
            'so.created_at', 'so.transaction_date', 'so.channel_shop_id',
            'cs.shop_id', 'cs.shop_name', 'cs.is_active as channel_active',
            'cs.order_sync_enabled', 'cs.disconnected_at', 'c.code as channel',
            'l.location_code', 'l.location_name',
        ];
        foreach ([
            'status', 'wms_status', 'channel_status', 'channel_status_raw',
            'channel_fulfillment_status', 'is_canceled', 'handed_to_warehouse_at',
        ] as $column) {
            $select[] = Schema::hasColumn('sales_orders', $column)
                ? 'so.'.$column
                : DB::raw('NULL as '.$column);
        }

        $orders = DB::table('sales_orders as so')
            ->leftJoin('channel_shops as cs', function ($join): void {
                $join->whereRaw('cs.id::text = so.channel_shop_id');
            })
            ->leftJoin('channels as c', 'c.id', '=', 'cs.channel_id')
            ->leftJoin('locations as l', 'l.id', '=', 'so.location_id')
            ->where(function ($query) use ($reference): void {
                $query->where('so.salesorder_no', $reference)
                    ->orWhere('so.channel_order_no', $reference);
            })
            ->get($select);

        $webhooks = $this->findWebhookRows($reference);
        $orderReports = $orders->map(fn (object $order): array => $this->orderReport($order))->values()->all();

        return [
            'reference' => $reference,
            'found_in_wms' => $orders->isNotEmpty(),
            'orders' => $orderReports,
            'webhooks' => $webhooks->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'channel' => (string) $row->channel,
                'shop_id' => (string) ($row->shop_id ?? ''),
                'location_code' => (string) ($row->location_code ?? 'unknown'),
                'event_type' => (string) ($row->event_type ?? ''),
                'status' => (string) $row->status,
                'error' => $row->error,
                'received_at' => $row->received_at,
                'processed_at' => $row->processed_at,
                'order_sync_enabled' => $row->order_sync_enabled === null ? null : (bool) $row->order_sync_enabled,
                'replayable' => in_array(strtolower((string) $row->status), ['skipped', 'failed'], true)
                    && $this->isOrderWebhook((string) $row->channel, (string) ($row->event_type ?? '')),
            ])->values()->all(),
            'actions' => [
                'can_include' => $this->canInclude($orders, $webhooks),
                'can_delete' => $this->canDelete(collect($orderReports)),
            ],
        ];
    }

    public function include(string $reference): array
    {
        $audit = $this->lookup($reference);
        $orders = collect($audit['orders']);
        if ($orders->contains(fn (array $order): bool => in_array($order['location_code'], self::LOCATION_CODES, true))) {
            return [
                'action' => 'include',
                'result' => 'already_in_wms',
                'message' => 'Pesanan sudah ada di WMS dan tidak dibuat ulang.',
                'audit' => $audit,
            ];
        }
        if ($orders->isNotEmpty()) {
            throw new RuntimeException('Pesanan ditemukan, tetapi bukan milik Gudang Kecil atau Gudang Pusat.');
        }

        $webhook = collect($audit['webhooks'])
            ->first(fn (array $row): bool => (bool) $row['replayable']
                && in_array($row['location_code'], self::LOCATION_CODES, true)
                && (bool) $row['order_sync_enabled']);
        if ($webhook === null) {
            throw new RuntimeException('Pesanan tidak ditemukan di WMS atau intake channel masih tertutup; buka intake lalu cek ulang.');
        }

        $lock = Cache::lock('cutover:include-order:'.$webhook['id'], 60);
        if (! $lock->get()) {
            throw new RuntimeException('Pesanan sedang diproses oleh permintaan lain. Tunggu sebentar lalu cek ulang.');
        }

        try {
            $row = DB::transaction(function () use ($webhook): ?ChannelWebhookInbox {
                $record = ChannelWebhookInbox::query()->lockForUpdate()->find($webhook['id']);
                if (! $record || ! in_array(strtolower((string) $record->status->value), ['skipped', 'failed'], true)) {
                    return null;
                }
                $record->update([
                    'status' => 'RECEIVED',
                    'error' => null,
                    'next_attempt_at' => null,
                    'updated_at' => now(),
                ]);

                return $record->fresh();
            }, 3);

            if (! $row || ! $this->webhookService->dispatchInbox($row)) {
                throw new RuntimeException('Webhook ditemukan, tetapi gagal dimasukkan ke antrean proses.');
            }

            return [
                'action' => 'include',
                'result' => 'replay_queued',
                'message' => 'Webhook pesanan dimasukkan kembali ke antrean WMS.',
                'audit' => $this->lookup($reference),
            ];
        } finally {
            $lock->release();
        }
    }

    public function delete(string $reference): array
    {
        $audit = $this->lookup($reference);
        $orders = collect($audit['orders']);
        $inScope = $orders->filter(fn (array $order): bool => in_array($order['location_code'], self::LOCATION_CODES, true));

        if ($orders->count() > 1) {
            throw new RuntimeException('Nomor pesanan cocok dengan lebih dari satu order. Penghapusan diblokir.');
        }
        if ($inScope->isEmpty()) {
            if ($orders->isNotEmpty()) {
                throw new RuntimeException('Pesanan ditemukan, tetapi bukan milik Gudang Kecil atau Gudang Pusat.');
            }

            return [
                'action' => 'delete',
                'result' => 'already_absent',
                'message' => 'Pesanan sudah tidak ada di WMS.',
                'audit' => $audit,
            ];
        }
        if ($inScope->count() !== 1) {
            throw new RuntimeException('Nomor pesanan cocok dengan lebih dari satu order internal. Penghapusan diblokir.');
        }

        $order = $inScope->first();
        if (! (bool) data_get($order, 'can_delete')) {
            throw new RuntimeException('Pesanan sudah diproses atau memiliki relasi proses gudang sehingga tidak boleh dihapus.');
        }

        $deleted = DB::transaction(function () use ($order): int {
            $locked = DB::table('sales_orders')->where('id', $order['id'])->lockForUpdate()->first();
            if (! $locked || ! $this->orderCanDelete((object) $locked)) {
                throw new RuntimeException('Kondisi pesanan berubah saat akan dihapus. Audit ulang terlebih dahulu.');
            }
            foreach (self::ORDER_CHILD_TABLES as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'order_id') && DB::table($table)->where('order_id', $locked->id)->exists()) {
                    throw new RuntimeException('Relasi proses pesanan muncul saat penghapusan; proses dibatalkan.');
                }
            }

            return DB::table('sales_orders')->where('id', $locked->id)->delete();
        }, 3);

        return [
            'action' => 'delete',
            'result' => $deleted === 1 ? 'deleted' : 'not_deleted',
            'message' => $deleted === 1 ? 'Pesanan berhasil dihapus dari WMS.' : 'Pesanan tidak dihapus.',
            'audit' => $this->lookup($reference),
        ];
    }

    private function orderReport(object $order): array
    {
        $children = [];
        foreach (self::ORDER_CHILD_TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'order_id')) {
                $children[$table] = (int) DB::table($table)->where('order_id', $order->id)->count();
            }
        }
        $processed = in_array(strtolower((string) ($order->status ?? '')), self::PROCESSED_STATUSES, true)
            || ($order->handed_to_warehouse_at !== null);

        return [
            'id' => (string) $order->id,
            'internal_order_no' => $order->salesorder_no,
            'channel_order_no' => $order->channel_order_no,
            'source' => $order->source,
            'location_code' => $order->location_code,
            'location_name' => $order->location_name,
            'internal_status' => $order->status,
            'wms_status' => $order->wms_status,
            'channel_status' => $order->channel_status,
            'channel_status_raw' => $order->channel_status_raw,
            'channel_fulfillment_status' => $order->channel_fulfillment_status,
            'is_canceled' => (bool) $order->is_canceled,
            'handed_to_warehouse_at' => $order->handed_to_warehouse_at,
            'created_at' => $order->created_at,
            'transaction_date' => $order->transaction_date,
            'channel' => $order->channel,
            'shop_id' => $order->shop_id,
            'shop_name' => $order->shop_name,
            'channel_active' => $order->channel_active === null ? null : (bool) $order->channel_active,
            'order_sync_enabled' => $order->order_sync_enabled === null ? null : (bool) $order->order_sync_enabled,
            'channel_disconnected_at' => $order->disconnected_at,
            'processed' => $processed,
            'child_counts' => $children,
            'can_delete' => ! $processed && array_sum($children) === 0,
        ];
    }

    private function orderCanDelete(object $order): bool
    {
        return ! in_array(strtolower((string) ($order->status ?? '')), self::PROCESSED_STATUSES, true)
            && (! Schema::hasColumn('sales_orders', 'handed_to_warehouse_at') || $order->handed_to_warehouse_at === null);
    }

    private function canDelete(Collection $orders): bool
    {
        return $orders->count() === 1
            && in_array($orders->first()['location_code'], self::LOCATION_CODES, true)
            && (bool) $orders->first()['can_delete'];
    }

    private function canInclude(Collection $orders, Collection $webhooks): bool
    {
        return $orders->isEmpty()
            && $webhooks->contains(fn (object $row): bool => in_array(strtolower((string) $row->status), ['skipped', 'failed'], true)
                && $this->isOrderWebhook((string) $row->channel, (string) ($row->event_type ?? ''))
                && in_array($row->location_code, self::LOCATION_CODES, true)
                && (bool) $row->order_sync_enabled);
    }

    private function findWebhookRows(string $reference): Collection
    {
        if (! Schema::hasTable('channel_webhook_inbox')) {
            return collect();
        }
        $needle = addcslashes($reference, '\\%_');

        return DB::table('channel_webhook_inbox as wi')
            ->leftJoin('channel_shops as cs', 'cs.shop_id', '=', 'wi.shop_id')
            ->leftJoin('locations as l', 'l.id', '=', 'cs.stock_source_location_id')
            ->whereRaw("CAST(wi.payload AS TEXT) LIKE ? ESCAPE '\\'", ['%'.$needle.'%'])
            ->orderByDesc('received_at')
            ->limit(100)
            ->get([
                'wi.id', 'wi.channel', 'wi.shop_id', 'wi.event_type', 'wi.status', 'wi.error',
                'wi.received_at', 'wi.processed_at', 'wi.payload', 'l.location_code',
                'cs.order_sync_enabled',
            ])
            ->filter(function (object $row) use ($reference): bool {
                $payload = is_array($row->payload) ? $row->payload : json_decode((string) $row->payload, true);

                return is_array($payload) && in_array($reference, $this->extractOrderReferences((string) $row->channel, $payload), true);
            })
            ->values();
    }

    private function extractOrderReferences(string $channel, array $payload): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $references = [];
        $add = static function (array &$target, mixed $value): void {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $target[] = trim((string) $value);
            }
        };
        switch (strtolower($channel)) {
            case 'shopee':
                $add($references, $data['ordersn'] ?? $data['order_sn'] ?? null);
                break;
            case 'lazada':
                $add($references, $data['trade_order_id'] ?? $data['order_id'] ?? $data['reverse_order_id'] ?? null);
                break;
            case 'tiktok':
                $add($references, $data['order_id'] ?? $data['main_order_id'] ?? $data['reverse_order_id'] ?? null);
                break;
            case 'woocommerce':
                $add($references, $payload['id'] ?? null);
                break;
        }

        return array_values(array_unique($references));
    }

    private function isOrderWebhook(string $channel, string $eventType): bool
    {
        return match (strtolower($channel)) {
            'shopee' => in_array($eventType, ['3'], true),
            'tiktok' => in_array($eventType, ['1', '3', '11'], true),
            'lazada' => in_array($eventType, ['0'], true),
            'woocommerce' => str_starts_with(strtolower($eventType), 'order'),
            default => false,
        };
    }

    private function normalizeReference(string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '' || mb_strlen($reference) > 128) {
            throw new RuntimeException('Nomor pesanan wajib diisi dan maksimal 128 karakter.');
        }

        return $reference;
    }
}
