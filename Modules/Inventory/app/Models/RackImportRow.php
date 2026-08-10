<?php

namespace Modules\Inventory\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RackImportRow extends Model
{
    use HasUuid7;

    public $timestamps = false;

    protected $fillable = [
        'batch_id',
        'row_no',
        'raw_sku',
        'raw_location',
        'raw_bin',
        'item_id',
        'location_id',
        'bin_id',
        'status',
        'message',
        'product_name',
        'current_bin',
        'created_at',
    ];

    protected $casts = [
        'row_no' => 'integer',
        'created_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(RackImportBatch::class, 'batch_id');
    }
}
