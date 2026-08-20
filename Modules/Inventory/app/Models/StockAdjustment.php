<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid7;

/**
 * @property string $id
 * @property string $adjustment_no
 * @property \Illuminate\Support\Carbon|null $transaction_date
 * @property string $location_id
 * @property bool $is_beginning_balance
 * @property string|null $notes
 * @property string $created_by
 */
class StockAdjustment extends Model
{
    use HasUuid7, SoftDeletes;

    protected $fillable = [
        'adjustment_no',
        'transaction_date',
        'location_id',
        'is_beginning_balance',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'transaction_date' => 'datetime',
        'is_beginning_balance' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\Location::class);
    }
}
