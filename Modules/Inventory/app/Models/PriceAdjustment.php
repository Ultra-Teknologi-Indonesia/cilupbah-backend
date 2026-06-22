<?php

namespace Modules\Inventory\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PriceAdjustment extends Model
{
    use HasUuid7, SoftDeletes;

    const STATUS_DRAFT = 'draft';
    const STATUS_APPLIED = 'applied';
    const STATUS_CANCELLED = 'cancelled';

    const TYPE_ONLINE = 'online';
    const TYPE_OFFLINE = 'offline';

    protected $fillable = [
        'adjustment_no',
        'adjustment_date',
        'type',
        'status',
        'notes',
        'created_by',
        'applied_by',
        'applied_at',
    ];

    protected $casts = [
        'adjustment_date' => 'date',
        'applied_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PriceAdjustmentItem::class);
    }
}
