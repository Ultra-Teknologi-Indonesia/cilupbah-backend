<?php

namespace Modules\Outbound\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PacklistItemScanEvent extends Model
{
    use HasUuid7;

    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'packlist_id',
        'packlist_item_id',
        'scanned_by',
        'qty_before',
        'qty_after',
    ];

    public function packlist(): BelongsTo
    {
        return $this->belongsTo(Packlist::class);
    }

    public function packlistItem(): BelongsTo
    {
        return $this->belongsTo(PacklistItem::class);
    }
}
