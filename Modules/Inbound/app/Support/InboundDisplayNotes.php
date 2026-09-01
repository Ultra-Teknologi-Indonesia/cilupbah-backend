<?php

declare(strict_types=1);

namespace Modules\Inbound\Support;

use Illuminate\Support\Collection;
use Modules\Inbound\Models\Inbound;
use Modules\Inventory\Models\Putaway;

final class InboundDisplayNotes
{
    public static function resolve(Inbound $inbound): ?string
    {
        $notes = collect();

        self::append($notes, $inbound->notes);
        self::appendRejectionNotes($notes, $inbound);
        self::appendPutawayNotes($notes, $inbound);

        $resolved = $notes
            ->map(static fn (string $note): string => trim($note))
            ->filter(static fn (string $note): bool => $note !== '')
            ->unique()
            ->values()
            ->implode('; ');

        return $resolved !== '' ? $resolved : null;
    }

    private static function appendRejectionNotes(Collection $notes, Inbound $inbound): void
    {
        $items = $inbound->relationLoaded('items') ? $inbound->items : collect();
        $rejectedQty = (int) $items->sum(static fn ($item): int => (int) ($item->rejected_qty ?? 0));

        if ($rejectedQty > 0) {
            self::append($notes, "reject {$rejectedQty}pcs");
        }

        $items
            ->pluck('rejection_note')
            ->filter(static fn ($note): bool => trim((string) $note) !== '')
            ->each(static fn ($note) => self::append($notes, (string) $note));
    }

    private static function appendPutawayNotes(Collection $notes, Inbound $inbound): void
    {
        $putaways = collect();

        if ($inbound->relationLoaded('putaways')) {
            $putaways = $putaways->merge($inbound->putaways);
        }

        if ($inbound->relationLoaded('directPutaways')) {
            $putaways = $putaways->merge($inbound->directPutaways);
        }

        if ($putaways->isEmpty()) {
            return;
        }

        $putaways
            ->unique('id')
            ->filter(static fn (Putaway $putaway): bool => $putaway->status !== Putaway::STATUS_CANCELLED)
            ->sortByDesc('created_at')
            ->pluck('notes')
            ->filter(fn ($note): bool => self::isMeaningfulPutawayNote((string) $note, $inbound))
            ->each(static fn ($note) => self::append($notes, (string) $note));
    }

    private static function isMeaningfulPutawayNote(string $note, Inbound $inbound): bool
    {
        $note = trim($note);

        if ($note === '') {
            return false;
        }

        if ($note === "Manual Putaway from Inbound {$inbound->transaction_number}") {
            return false;
        }

        return ! str_starts_with($note, 'Manual Putaway gabungan dari ');
    }

    private static function append(Collection $notes, ?string $note): void
    {
        $note = trim((string) $note);

        if ($note !== '') {
            $notes->push($note);
        }
    }
}
