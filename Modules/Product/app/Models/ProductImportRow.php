<?php

namespace Modules\Product\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImportRow extends Model
{
    use HasUuid7;

    public $timestamps = false;

    public const STATUS_VALID = 'valid';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'import_batch_id',
        'row_number',
        'sku',
        'name',
        'category_name',
        'sell_price',
        'status',
        'message',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'row_number' => 'integer',
        'sell_price' => 'float',
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (ProductImportRow $row) {
            if (empty($row->created_at)) {
                $row->created_at = now();
            }
        });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductImportBatch::class, 'import_batch_id');
    }
}
