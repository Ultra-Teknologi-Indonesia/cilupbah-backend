<?php

namespace Modules\Outbound\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid7;

class FulfillmentRemoval extends Model
{
    use HasUuid7;

    protected $table = 'fulfillment_removals';

    public const UPDATED_AT = null;

    public const STAGE_PICKING = 'picking';
    public const STAGE_PACKING = 'packing';
    public const STAGE_SHIPPING = 'shipping';

    protected $fillable = [
        'order_id',
        'stage',
        'removed_by',
        'reason',
        'reversed_stock',
    ];

    protected $casts = [
        'reversed_stock' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(\Modules\Sales\Models\SalesOrder::class, 'order_id');
    }
}
