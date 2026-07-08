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

    public const STATUS_BARU_DIBUAT = 'BARU_DIBUAT';
    public const STATUS_SEDANG_DIJALAN = 'SEDANG_DIJALAN';
    public const STATUS_SELESAI = 'SELESAI';

    protected $fillable = [
        'transfer_number',
        'location_id',
        'status',
        'transfer_date',
        'created_by',
        'notes',
        'printed_at',
        'printed_by',
    ];

    protected $casts = [
        'transfer_date' => 'date',
        'printed_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(BinTransferItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(BinTransferReceipt::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\Modules\Warehouse\Models\Location::class);
    }
}
