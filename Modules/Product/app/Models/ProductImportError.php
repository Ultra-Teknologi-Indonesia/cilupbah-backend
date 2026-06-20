<?php

namespace Modules\Product\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImportError extends Model
{
    use HasUuid7;

    public $timestamps = false;

    protected $fillable = [
        'import_batch_id',
        'row_number',
        'attribute',
        'message',
        'row_snapshot',
    ];

    protected $casts = [
        'row_number' => 'integer',
        'row_snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (ProductImportError $error) {
            if (empty($error->created_at)) {
                $error->created_at = now();
            }
        });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductImportBatch::class, 'import_batch_id');
    }
}
