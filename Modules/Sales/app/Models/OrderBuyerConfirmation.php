<?php

namespace Modules\Sales\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Models\ProductVariant;

class OrderBuyerConfirmation extends Model
{
    use HasUuid7;

    public const OUTCOME_CANCEL = 'CANCEL';
    public const OUTCOME_REPLACE = 'REPLACE';
    public const OUTCOME_REMOVE = 'REMOVE';
    public const OUTCOME_WAIT = 'WAIT';

    public const OUTCOMES = [
        self::OUTCOME_CANCEL,
        self::OUTCOME_REPLACE,
        self::OUTCOME_REMOVE,
        self::OUTCOME_WAIT,
    ];

    protected $table = 'order_buyer_confirmations';

    protected $fillable = [
        'order_id',
        'order_item_id',
        'item_id',
        'qty_short',
        'outcome',
        'replacement_item_id',
        'note',
        'raised_by',
        'raised_at',
        'confirmed_by',
        'confirmed_at',
        'resolved_at',
    ];

    protected $casts = [
        'qty_short' => 'integer',
        'raised_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'order_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class, 'order_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'item_id');
    }

    public function replacement(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'replacement_item_id');
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeAwaitingDecision(Builder $query): Builder
    {
        return $query->whereNull('resolved_at')->whereNull('outcome');
    }

    public function scopeWaitingStock(Builder $query): Builder
    {
        return $query->whereNull('resolved_at')->where('outcome', self::OUTCOME_WAIT);
    }
}
