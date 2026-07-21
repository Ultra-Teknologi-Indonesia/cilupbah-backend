<?php

namespace Modules\Outbound\Services\Logistics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Outbound\Contracts\DriverCallResult;
use Modules\Outbound\Contracts\MarketPlaceLogisticsInterface;
use Modules\Outbound\Models\Shipment;
use Modules\Outbound\Models\ShipmentTrackingEvent;
use Modules\Sales\Models\SalesOrder;

abstract class AbstractLogisticsService implements MarketPlaceLogisticsInterface
{

    abstract protected function driverCallMethod(): string;

    abstract protected function callSingle(SalesOrder $order, int $shipperId): array;

    protected function retrySingle(SalesOrder $order, int $shipperId): array
    {
        return $this->callSingle($order, $shipperId);
    }

    public function callDriver(array $orderIds, int $shipperId): DriverCallResult
    {
        return $this->invoke($orderIds, $shipperId, fn ($o) => $this->callSingle($o, $shipperId));
    }

    public function retryCallDriver(array $orderIds, int $shipperId): DriverCallResult
    {
        return $this->invoke($orderIds, $shipperId, fn ($o) => $this->retrySingle($o, $shipperId));
    }

    public function getTrackingStatus(string $orderId): array
    {
        return [];
    }

    public function readyToShip(SalesOrder $order): array
    {
        return [
            'status'  => 'failed',
            'message' => sprintf(
                "Ready-to-ship belum diimplementasikan untuk source '%s'.",
                strtolower((string) $order->source),
            ),
        ];
    }

    private const NON_CALLABLE_CHANNEL_STATUSES = [
        'CANCELLED', 'IN_CANCEL', 'RETURN_REQUESTED', 'RETURNED',
        'SHIPPED', 'TO_CONFIRM_RECEIVE', 'COMPLETED',
    ];

    protected function invoke(array $orderIds, int $shipperId, callable $handler): DriverCallResult
    {
        $orders = SalesOrder::query()->whereIn('id', $orderIds)->get()->keyBy('id');
        $results = [];

        foreach ($orderIds as $id) {
            $order = $orders->get($id);
            if (! $order) {
                $results[] = [
                    'order_id' => $id,
                    'status' => DriverCallResult::STATUS_FAILED,
                    'message' => 'Pesanan tidak ditemukan.',
                ];
                continue;
            }

            if ($guard = $this->guardCallable($order)) {
                $this->persistFailure($order, ['message' => $guard]);
                $results[] = ['order_id' => $id, 'status' => DriverCallResult::STATUS_FAILED, 'message' => $guard];
                continue;
            }

            try {
                $outcome = $handler($order);
                $status = $outcome['status'] ?? DriverCallResult::STATUS_FAILED;

                if ($status === DriverCallResult::STATUS_SUCCESS) {
                    $this->persistSuccess($order, $shipperId, $outcome);
                } else {
                    $this->persistFailure($order, $outcome);
                }

                $results[] = array_merge(['order_id' => $id], $outcome);
            } catch (\Throwable $e) {
                Log::warning('Panggil driver gagal', [
                    'order_id' => $id,
                    'source' => $order->source ?? null,
                    'error' => $e->getMessage(),
                ]);
                $this->persistFailure($order, [
                    'message'  => $e->getMessage(),
                    'response' => ['exception' => $e->getMessage(), 'class' => get_class($e)],
                ]);
                $results[] = [
                    'order_id' => $id,
                    'status' => DriverCallResult::STATUS_FAILED,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return new DriverCallResult($results);
    }

    private function guardCallable(SalesOrder $order): ?string
    {
        $cs = strtoupper((string) $order->channel_status);

        if (! in_array($cs, self::NON_CALLABLE_CHANNEL_STATUSES, true)) {
            return null;
        }

        return match (true) {
            in_array($cs, ['CANCELLED', 'IN_CANCEL'], true)                    => 'Pesanan sudah dibatalkan — tidak bisa panggil driver.',
            in_array($cs, ['RETURN_REQUESTED', 'RETURNED'], true)              => 'Pesanan dalam proses retur — tidak bisa panggil driver.',
            default                                                            => 'Pesanan sudah dikirim/selesai — driver tidak perlu dipanggil lagi.',
        };
    }

    protected function persistFailure(SalesOrder $order, array $outcome): void
    {
        $order->forceFill([
            'driver_call_status'       => 'failed',
            'driver_call_message'      => isset($outcome['message']) ? mb_substr((string) $outcome['message'], 0, 500) : null,
            'driver_call_response'     => $outcome['response'] ?? $outcome,
            'driver_call_attempted_at' => now(),
        ])->save();
    }

    protected function persistSuccess(SalesOrder $order, int $shipperId, array $outcome): void
    {
        DB::transaction(function () use ($order, $shipperId, $outcome) {
            $shipment = Shipment::query()
                ->whereHas('orders', fn ($q) => $q->where('order_id', $order->id))
                ->latest('id')
                ->first();

            if ($shipment) {
                $shipment->fill([
                    'driver_call_status' => Shipment::DRIVER_STATUS_CALLED,
                    'driver_call_method' => $this->driverCallMethod(),
                    'driver_called_at' => now(),
                    'driver_called_by' => (string) $shipperId,
                    'shipper_id' => $shipperId,
                ])->save();

                if (! empty($outcome['tracking_number'])) {
                    $shipment->orders()
                        ->where('order_id', $order->id)
                        ->update(['tracking_number' => $outcome['tracking_number']]);
                }

                ShipmentTrackingEvent::create([
                    'shipment_id' => $shipment->id,
                    'source' => strtolower((string) $order->source),
                    'event_type' => ShipmentTrackingEvent::EVENT_DRIVER_ASSIGNED,
                    'raw_payload' => $outcome,
                    'occurred_at' => now(),
                    'received_at' => now(),
                ]);
            }
        });
    }
}
