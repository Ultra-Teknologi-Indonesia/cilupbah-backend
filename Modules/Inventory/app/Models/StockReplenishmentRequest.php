<?php

namespace Modules\Inventory\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockReplenishmentRequest extends Model
{
    use HasUuid7;

    protected $table = 'stock_replenishment_requests';

    public const STATUS_PENDING  = 'PENDING';
    public const STATUS_ACCEPTED = 'ACCEPTED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_DONE     = 'DONE';

    protected $fillable = [
        'requested_by_user_id',
        'from_location_id',
        'to_location_id',
        'status',
        'assignee_user_id',
        'accepted_by_user_id',
        'rejected_by_user_id',
        'requested_at',
        'accepted_at',
        'rejected_at',
        'done_at',
        'reject_reason',
        'note',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'accepted_at'  => 'datetime',
        'rejected_at'  => 'datetime',
        'done_at'      => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(StockReplenishmentRequestItem::class, 'request_id');
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\Location::class, 'to_location_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'assignee_user_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'requested_by_user_id');
    }
}
