<?php

declare(strict_types=1);

namespace Modules\Outbound\Support;

use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\Shipment;
use Modules\Sales\Models\SalesOrder;

/**
 * Resolves one order to the single current position shown by Proses Pesanan.
 *
 * Some UI views intentionally share the same backend stage (for example,
 * finish-pick is the last Picking view and the first Packing view). Export
 * must therefore choose the next operational position and never duplicate an
 * order in two process rows.
 */
final class ProcessOrderStatusResolver
{
    /**
     * @return array{stage: string, sub_status: string}|null
     */
    public function resolve(SalesOrder $order): ?array
    {
        return match ($order->status) {
            'reserved' => $this->resolveReserved($order),
            'picked' => $this->resolvePicked($order),
            'packed' => $this->resolvePacked($order),
            'cancelled' => $this->resolveCancelled($order),
            'shipped' => [
                'stage' => $order->received_date ? 'Selesai' : 'Sudah Dikirim',
                'sub_status' => '',
            ],
            default => null,
        };
    }

    /**
     * @return array{stage: string, sub_status: string}|null
     */
    private function resolveReserved(SalesOrder $order): ?array
    {
        $picklistStatuses = $order->picklistItems
            ->map(fn ($item): ?string => $item->picklist?->status)
            ->filter()
            ->unique();

        if ($order->pick_failed_at !== null
            || $picklistStatuses->contains(Picklist::STATUS_FAILED)) {
            return [
                'stage' => 'Picking',
                'sub_status' => 'Gagal',
            ];
        }

        if ($picklistStatuses->contains(
            fn (?string $status): bool => in_array($status, [
                Picklist::STATUS_DRAFT,
                Picklist::STATUS_IN_PROGRESS,
            ], true),
        )) {
            return [
                'stage' => 'Picking',
                'sub_status' => 'Diproses',
            ];
        }

        if ($order->handed_to_warehouse_at !== null && $picklistStatuses->isEmpty()) {
            return [
                'stage' => 'Picking',
                'sub_status' => 'Belum Mulai',
            ];
        }

        return null;
    }

    /**
     * @return array{stage: string, sub_status: string}|null
     */
    private function resolvePicked(SalesOrder $order): ?array
    {
        $packlistStatus = $order->packlist?->status;

        if (in_array($packlistStatus, [
            Packlist::STATUS_DRAFT,
            Packlist::STATUS_IN_PROGRESS,
        ], true)) {
            return [
                'stage' => 'Packing',
                'sub_status' => 'Diproses',
            ];
        }

        if ($packlistStatus === null || $packlistStatus === Packlist::STATUS_CANCELLED) {
            return [
                'stage' => 'Packing',
                'sub_status' => 'Belum Mulai',
            ];
        }

        return null;
    }

    /**
     * @return array{stage: string, sub_status: string}|null
     */
    private function resolvePacked(SalesOrder $order): ?array
    {
        $shipment = $order->shipmentOrders
            ->map(fn ($shipmentOrder) => $shipmentOrder->shipment)
            ->filter()
            ->filter(fn ($shipment): bool => $shipment->status === Shipment::STATUS_SCHEDULED)
            ->first();

        if ($shipment !== null) {
            return [
                'stage' => 'Shipping',
                'sub_status' => 'Jadwal Pengiriman',
            ];
        }

        if ($order->shipmentOrders->isEmpty()) {
            return [
                'stage' => 'Shipping',
                'sub_status' => 'Siap Kirim',
            ];
        }

        return null;
    }

    /**
     * @return array{stage: string, sub_status: string}|null
     */
    private function resolveCancelled(SalesOrder $order): ?array
    {
        $hasScheduledShipment = $order->shipmentOrders
            ->map(fn ($shipmentOrder) => $shipmentOrder->shipment)
            ->filter()
            ->contains(fn ($shipment): bool => $shipment->status === Shipment::STATUS_SCHEDULED);

        if ($hasScheduledShipment) {
            return [
                'stage' => 'Shipping',
                'sub_status' => 'Jadwal Pengiriman',
            ];
        }

        if ($order->packlist?->status === Packlist::STATUS_COMPLETED
            && $order->shipmentOrders->isEmpty()) {
            return [
                'stage' => 'Packing',
                'sub_status' => 'Selesai',
            ];
        }

        if ($order->handed_to_warehouse_at !== null
            && $order->cancel_dismissed_at === null
            && $order->shipmentOrders->isEmpty()) {
            return [
                'stage' => 'Shipping',
                'sub_status' => 'Batal Pra-Manifest',
            ];
        }

        return null;
    }
}
