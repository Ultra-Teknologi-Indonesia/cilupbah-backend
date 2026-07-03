<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid7;

class BinTransfer extends Model
{
    use HasUuid7, SoftDeletes;

    protected $fillable = [
        'transfer_number',
        'location_id',
        'transfer_date',
        'created_by',
        'notes',
    ];

    protected $casts = [
        'transfer_date' => 'date',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(BinTransferItem::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\Location::class);
    }
}
