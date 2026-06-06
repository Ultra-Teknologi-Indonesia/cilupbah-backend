<?php

namespace Modules\Warranty\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarrantyClaim extends Model
{
    use HasUuid7;

    protected $fillable = [
        'warranty_id',
        'claim_number',
        'reason',
        'status',
        'resolution',
        'claimed_by',
        'claimed_at',
        'resolved_at',
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class);
    }
}
