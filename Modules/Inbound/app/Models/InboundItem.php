<?php

namespace Modules\Inbound\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\ProductVariant;

class InboundItem extends Model
{
    use HasUuid7;

    protected $fillable = [
        'inbound_id',
        'item_id',
        'expected_qty',
        'received_qty',
        'rejected_qty',
        'rejection_note',
        'putaway_qty',
        'reserved_qty',
        'discrepancy_qty',
        'discrepancy_note',
        'condition',
        'notes',
    ];

    public function inbound(): BelongsTo
    {
        return $this->belongsTo(Inbound::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'item_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(InboundReceipt::class);
    }

    public function isFullyReceived(): bool
    {
        return ($this->received_qty + ($this->rejected_qty ?? 0)) >= $this->expected_qty;
    }

    public function isFullyPutaway(): bool
    {
        return $this->putaway_qty >= $this->received_qty;
    }

    public function pendingPutawayQty(): int
    {
        return max(0, $this->received_qty - $this->putaway_qty - ($this->reserved_qty ?? 0));
    }

    public function remainingToReceiveQty(): int
    {
        return max(
            0,
            (int) $this->expected_qty
                - (int) ($this->received_qty ?? 0)
                - (int) ($this->rejected_qty ?? 0),
        );
    }

    public function scopeWithReceiptStats(Builder $q, ?string $userId = null): Builder
    {
        $userId ??= auth()->id();

        $totalSub = DB::table('inbound_receipts')
            ->selectRaw('COALESCE(SUM(qty), 0)')
            ->whereColumn('inbound_receipts.inbound_item_id', 'inbound_items.id');

        $q->addSelect(['received_total' => $totalSub]);

        if ($userId) {
            $meSub = DB::table('inbound_receipts')
                ->selectRaw('COALESCE(SUM(qty), 0)')
                ->whereColumn('inbound_receipts.inbound_item_id', 'inbound_items.id')
                ->where('inbound_receipts.received_by_user_id', $userId);
            $q->addSelect(['received_by_me' => $meSub]);
        } else {
            $q->selectRaw('0 as received_by_me');
        }

        return $q;
    }
}
