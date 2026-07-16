<?php

namespace Modules\Inbound\Models;

use App\Models\User;
use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Warehouse\Models\LocationBin;

class InboundReceipt extends Model
{
    use HasUuid7;

    protected $fillable = [
        'inbound_item_id',
        'qty',
        'bin_id',
        'batch_no',
        'serial_no',
        'condition',
        'received_by_user_id',
        'received_date',
    ];

    protected $casts = [
        'received_date' => 'datetime',
    ];

    public function inboundItem(): BelongsTo
    {
        return $this->belongsTo(InboundItem::class);
    }

    public function bin(): BelongsTo
    {
        return $this->belongsTo(LocationBin::class, 'bin_id');
    }

    public function receivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }
}
