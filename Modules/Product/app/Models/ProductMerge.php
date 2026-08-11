<?php

namespace Modules\Product\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductMerge extends Model
{
    use HasUuid7;

    protected $table = 'product_merges';

    protected $fillable = [
        'product_id',
        'master_name',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected static function booted(): void
    {
        static::saved(function () {
            \Illuminate\Support\Facades\Cache::forget('product_merges_context');
        });

        static::deleted(function () {
            \Illuminate\Support\Facades\Cache::forget('product_merges_context');
        });
    }
}
