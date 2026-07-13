<?php

namespace Modules\Sales\Observers;

use Illuminate\Support\Facades\Cache;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderStatusHistory;
use Modules\Sales\Services\SalesOrderService;

class SalesOrderAuditObserver
{
    private const STATUS_ACTION_MAP = [
        'reserved' => 'PROCESS',
        'picked'   => 'FINISH_PICK',
        'packed'   => 'FINISH_PACK',
        'shipped'  => 'SHIPPED',
    ];

    private const CHANNEL_STATUS_LABELS = [
        'TO_CONFIRM_RECEIVE' => 'RECEIVED_BY_BUYER',
        'COMPLETED'          => 'COMPLETED',
    ];

    public function updated(SalesOrder $order): void
    {
        $service = app(SalesOrderService::class);

        if ($order->wasChanged('status')) {
            $prevStatus = $order->getOriginal('status');
            $newStatus  = $order->status;
            $action     = self::STATUS_ACTION_MAP[$newStatus] ?? null;

            if ($action && ! $this->recentlyLogged($order, $action)) {
                $service->logFieldChange(
                    $order,
                    $action,
                    ['status' => $prevStatus],
                    ['status' => $newStatus],
                    $order->salesorder_no,
                );
            }
        }

        if ($order->wasChanged('is_canceled') && $order->is_canceled && ! $this->recentlyLogged($order, 'CANCELLED')) {
            $service->logFieldChange(
                $order,
                'CANCELLED',
                [
                    'is_canceled'   => (bool) $order->getOriginal('is_canceled'),
                    'cancel_reason' => $order->getOriginal('cancel_reason'),
                ],
                [
                    'is_canceled'   => true,
                    'cancel_reason' => $order->cancel_reason,
                ],
                $order->salesorder_no,
            );
        }

        if ($order->wasChanged('is_paid') && $order->is_paid && ! $this->recentlyLogged($order, 'PAID')) {
            $service->logFieldChange(
                $order,
                'PAID',
                [
                    'is_paid'      => (bool) $order->getOriginal('is_paid'),
                    'payment_date' => $order->getOriginal('payment_date'),
                ],
                [
                    'is_paid'      => true,
                    'payment_date' => $order->payment_date,
                ],
                $order->salesorder_no,
            );
        }

        if ($order->wasChanged('channel_status')) {
            $newChannelStatus = $order->channel_status;
            $action = self::CHANNEL_STATUS_LABELS[$newChannelStatus] ?? 'CHANNEL_STATUS';

            if (! $this->recentlyLogged($order, $action)) {
                $service->logFieldChange(
                    $order,
                    $action,
                    ['channel_status' => $order->getOriginal('channel_status')],
                    ['channel_status' => $newChannelStatus],
                    $order->salesorder_no,
                );
            }
        }

        $trackingFields = ['tracking_number', 'shipping_provider', 'courier'];
        $trackingDirty = collect($trackingFields)->filter(fn ($f) => $order->wasChanged($f))->values()->all();
        if (! empty($trackingDirty) && ! $this->recentlyLogged($order, 'TRACKING_UPDATED')) {
            $prev = [];
            $new  = [];
            foreach ($trackingDirty as $field) {
                $prev[$field] = $order->getOriginal($field);
                $new[$field]  = $order->{$field};
            }
            $service->logFieldChange(
                $order,
                'TRACKING_UPDATED',
                $prev,
                $new,
                $order->salesorder_no,
            );
        }

        $labelFields = ['awb_printed_count', 'label_printed_count', 'is_label_printed'];
        $labelDirty = collect($labelFields)->filter(fn ($f) => $order->wasChanged($f))->values()->all();
        if (! empty($labelDirty)) {
            $prev = [];
            $new  = [];
            foreach ($labelDirty as $field) {
                $prev[$field] = $order->getOriginal($field);
                $new[$field]  = $order->{$field};
            }
            $service->logFieldChange(
                $order,
                'LABEL_PRINTED',
                $prev,
                $new,
                $order->salesorder_no,
            );
        }
    }

    private function recentlyLogged(SalesOrder $order, string $action): bool
    {
        $key = 'so_audit:'.$order->id.':'.$action;
        if (Cache::has($key)) {
            return true;
        }

        $exists = SalesOrderStatusHistory::query()
            ->where('salesorder_id', $order->id)
            ->where('action', $action)
            ->where('created_at', '>=', now()->subSeconds(3))
            ->exists();

        if ($exists) {
            Cache::put($key, true, 5);
        }

        return $exists;
    }
}
