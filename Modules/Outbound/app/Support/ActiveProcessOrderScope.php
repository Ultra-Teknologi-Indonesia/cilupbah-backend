<?php

declare(strict_types=1);

namespace Modules\Outbound\Support;

use Illuminate\Database\Eloquent\Builder;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\Shipment;

/**
 * Database scope for the active queues shown on the Proses Pesanan board.
 *
 * Keep this allowlist intentionally narrow: delivered and completed orders
 * are historical and must never be exported from the active board.
 */
final class ActiveProcessOrderScope
{
    /** @var array<string, list<string>> */
    private const ALLOWED_SUB_STATUSES = [
        'picking' => ['belum', 'diproses', 'selesai'],
        'packing' => ['belum', 'diproses', 'selesai'],
        'shipping' => ['siap-kirim', 'jadwal', 'batal'],
    ];

    /** @var array<string, array{stage: string, sub_status: string}> */
    private const RESOLVED_STATUS = [
        'picking:belum' => ['stage' => 'Picking', 'sub_status' => 'Belum Mulai'],
        'picking:diproses' => ['stage' => 'Picking', 'sub_status' => 'Diproses'],
        'picking:selesai' => ['stage' => 'Packing', 'sub_status' => 'Belum Mulai'],
        'packing:belum' => ['stage' => 'Packing', 'sub_status' => 'Belum Mulai'],
        'packing:diproses' => ['stage' => 'Packing', 'sub_status' => 'Diproses'],
        'packing:selesai' => ['stage' => 'Packing', 'sub_status' => 'Selesai'],
        'shipping:siap-kirim' => ['stage' => 'Shipping', 'sub_status' => 'Siap Kirim'],
        'shipping:jadwal' => ['stage' => 'Shipping', 'sub_status' => 'Jadwal Pengiriman'],
        'shipping:batal' => ['stage' => 'Shipping', 'sub_status' => 'Batal Pra-Manifest'],
    ];

    public static function isAllowed(string $stage, string $subStatus): bool
    {
        return in_array($subStatus, self::ALLOWED_SUB_STATUSES[$stage] ?? [], true);
    }

    /** @return array{stage: string, sub_status: string} */
    public static function resolvedStatus(string $stage, string $subStatus): array
    {
        $key = strtolower($stage).':'.strtolower($subStatus);

        if (! isset(self::RESOLVED_STATUS[$key])) {
            throw new \InvalidArgumentException('Stage atau sub-status export tidak valid.');
        }

        return self::RESOLVED_STATUS[$key];
    }

    /** @return array{stage: string, sub_status: string} */
    public static function exportStatus(string $stage, string $subStatus): array
    {
        $labels = [
            'picking' => 'Picking',
            'packing' => 'Packing',
            'shipping' => 'Shipping',
        ];

        if (! self::isAllowed($stage, $subStatus)) {
            throw new \InvalidArgumentException('Stage atau sub-status export tidak valid.');
        }

        $subLabels = [
            'belum' => 'Belum Mulai',
            'diproses' => 'Diproses',
            'selesai' => 'Selesai',
            'siap-kirim' => 'Siap Kirim',
            'jadwal' => 'Jadwal Pengiriman',
            'batal' => 'Batal Pra-Manifest',
        ];

        return [
            'stage' => $labels[$stage],
            'sub_status' => $subLabels[$subStatus],
        ];
    }

    public static function matchesResolvedStatus(string $stage, string $subStatus, ?array $resolved): bool
    {
        if ($resolved === null || ! self::isAllowed($stage, $subStatus)) {
            return false;
        }

        // The board intentionally exposes the same finish-pack queue as both
        // Packing/Selesai and Shipping/Siap Kirim. Preserve that UI contract
        // while still validating every row against a resolved status.
        if (in_array($stage.':'.$subStatus, ['packing:selesai', 'shipping:siap-kirim'], true)) {
            return in_array($resolved, [
                self::resolvedStatus('packing', 'selesai'),
                self::resolvedStatus('shipping', 'siap-kirim'),
            ], true);
        }

        return $resolved === self::resolvedStatus($stage, $subStatus);
    }

    /**
     * Apply the exact active queue predicate before the export is chunked.
     */
    public function apply(Builder $query, string $stage, string $subStatus): Builder
    {
        if (! self::isAllowed($stage, $subStatus)) {
            throw new \InvalidArgumentException('Stage atau sub-status export tidak valid.');
        }

        return match ($stage.':'.$subStatus) {
            'picking:belum' => $query
                ->where('sales_orders.status', 'reserved')
                ->whereNotNull('sales_orders.handed_to_warehouse_at')
                ->whereDoesntHave('picklistItems'),

            'picking:diproses' => $query
                ->where('sales_orders.status', 'reserved')
                ->whereHas('picklistItems.picklist', fn (Builder $picklist): Builder => $picklist
                    ->whereIn('status', [Picklist::STATUS_DRAFT, Picklist::STATUS_IN_PROGRESS])),

            // The picking-complete queue is the packing "Belum Mulai" queue.
            'picking:selesai', 'packing:belum' => $query
                ->where('sales_orders.status', 'picked')
                ->whereDoesntHave('packlist', fn (Builder $packlist): Builder => $packlist
                    ->whereIn('status', [
                        Packlist::STATUS_DRAFT,
                        Packlist::STATUS_IN_PROGRESS,
                        Packlist::STATUS_COMPLETED,
                    ])),

            'packing:diproses' => $query
                ->where('sales_orders.status', 'picked')
                ->whereHas('packlist', fn (Builder $packlist): Builder => $packlist
                    ->whereIn('status', [Packlist::STATUS_DRAFT, Packlist::STATUS_IN_PROGRESS])),

            // The board's finish-pack list is also the shipping "Siap Kirim" list.
            'packing:selesai', 'shipping:siap-kirim' => $query
                ->where(function (Builder $order): void {
                    $order->where('sales_orders.status', 'packed')
                        ->orWhere(function (Builder $cancelled): void {
                            $cancelled
                                ->where('sales_orders.status', 'cancelled')
                                ->whereNull('sales_orders.cancel_dismissed_at')
                                ->whereHas('packlist', fn (Builder $packlist): Builder => $packlist
                                    ->where('status', Packlist::STATUS_COMPLETED));
                        });
                })
                ->whereDoesntHave('shipmentOrders'),

            'shipping:jadwal' => $query
                ->whereIn('sales_orders.status', ['packed', 'cancelled'])
                ->whereHas('shipmentOrders.shipment', fn (Builder $shipment): Builder => $shipment
                    ->where('status', Shipment::STATUS_SCHEDULED)),

            'shipping:batal' => $query
                ->where('sales_orders.status', 'cancelled')
                ->whereNotNull('sales_orders.handed_to_warehouse_at')
                ->whereNull('sales_orders.cancel_dismissed_at')
                ->whereDoesntHave('shipmentOrders'),

            /*
             * Keep the match exhaustive so a future tab cannot silently fall
             * back to an unscoped export.
             */
            default => throw new \InvalidArgumentException('Stage atau sub-status export tidak valid.'),
        };
    }
}
