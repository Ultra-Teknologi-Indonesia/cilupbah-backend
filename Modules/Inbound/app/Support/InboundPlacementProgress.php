<?php

namespace Modules\Inbound\Support;

use Illuminate\Support\Collection;
use Modules\Inbound\Models\Inbound;

final class InboundPlacementProgress
{
    public const STATUS_NOT_STARTED = 'NOT_STARTED';

    public const STATUS_PARTIAL = 'PARTIAL';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public static function decorate(Inbound $inbound): Inbound
    {
        $items = $inbound->relationLoaded('items')
            ? $inbound->items
            : $inbound->items()->get(['expected_qty', 'received_qty', 'putaway_qty', 'reserved_qty']);

        $summary = self::summarize($items, (string) $inbound->status, (string) $inbound->type);

        $inbound->setAttribute('receiving_status', (string) $inbound->status);
        $inbound->setAttribute('placement_status', $summary['status']);
        $inbound->setRelation('placement_summary', collect([
            'received_qty' => $summary['received_qty'],
            'putaway_qty' => $summary['putaway_qty'],
            'pending_qty' => $summary['pending_qty'],
            'reserved_qty' => $summary['reserved_qty'],
            'progress_percent' => $summary['progress_percent'],
            'is_consistent' => $summary['is_consistent'],
        ]));

        return $inbound;
    }

    public static function summarize(
        iterable $items,
        string $receivingStatus,
        string $inboundType = '',
    ): array {
        $rows = collect($items);
        $isSalesReturn = strtoupper($inboundType) === Inbound::TYPE_SALES_RETURN;

        $received = (int) $rows->sum(function ($item) use ($isSalesReturn): int {
            $actual = (int) ($item->received_qty ?? 0);

            if ($isSalesReturn && $actual === 0) {
                return (int) ($item->expected_qty ?? 0);
            }

            return $actual;
        });
        $putaway = (int) $rows->sum(fn ($item): int => (int) ($item->putaway_qty ?? 0));
        $reserved = (int) $rows->sum(fn ($item): int => (int) ($item->reserved_qty ?? 0));
        $pending = max(0, $received - $putaway);
        $isConsistent = $putaway <= $received && $reserved <= $pending;

        $status = match (true) {
            strtoupper($receivingStatus) === Inbound::STATUS_CANCELLED => self::STATUS_CANCELLED,
            $received <= 0, $putaway <= 0 => self::STATUS_NOT_STARTED,
            $putaway < $received => self::STATUS_PARTIAL,
            default => self::STATUS_COMPLETED,
        };

        return [
            'status' => $status,
            'received_qty' => $received,
            'putaway_qty' => $putaway,
            'pending_qty' => $pending,
            'reserved_qty' => $reserved,
            'progress_percent' => $received > 0
                ? min(100, (int) round(($putaway / $received) * 100))
                : 0,
            'is_consistent' => $isConsistent,
        ];
    }
}
