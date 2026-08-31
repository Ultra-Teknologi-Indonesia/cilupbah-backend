<?php

namespace Modules\Inventory\Models;

use App\Models\User;
use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Warehouse\Models\Location;

class StockReplenishmentRequest extends Model
{
    use HasUuid7;

    protected $table = 'stock_replenishment_requests';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_ACCEPTED = 'ACCEPTED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_DONE = 'DONE';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const SOURCE_MANUAL = 'MANUAL';

    public const SOURCE_MONITOR = 'MONITOR';

    public const SOURCE_AUTO = 'AUTO';

    public const SOURCE_MIXED = 'MIXED';

    protected $fillable = [
        'requested_by_user_id',
        'from_location_id',
        'to_location_id',
        'status',
        'source',
        'batch_key',
        'assignee_user_id',
        'transfer_out_id',
        'accepted_by_user_id',
        'rejected_by_user_id',
        'requested_at',
        'accepted_at',
        'rejected_at',
        'done_at',
        'last_reconciled_at',
        'cancelled_at',
        'reject_reason',
        'cancel_reason',
        'note',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'done_at' => 'datetime',
        'last_reconciled_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(StockReplenishmentRequestItem::class, 'request_id');
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function transferOut(): BelongsTo
    {
        return $this->belongsTo(InventoryTransfer::class, 'transfer_out_id');
    }
}
