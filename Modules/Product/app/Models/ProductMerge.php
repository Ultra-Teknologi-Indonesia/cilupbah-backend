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
        'is_representative',
    ];

    protected $casts = [
        'is_representative' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected static function booted(): void
    {
        static::saving(function (ProductMerge $merge) {
            if (! isset($merge->attributes['is_representative'])) {
                $productName = \Illuminate\Support\Facades\DB::table('products')->where('id', $merge->product_id)->value('name');
                if ($productName === $merge->master_name) {
                    $merge->is_representative = true;
                    \Illuminate\Support\Facades\DB::table('product_merges')
                        ->where('master_name', $merge->master_name)
                        ->where('product_id', '!=', $merge->product_id)
                        ->update(['is_representative' => false]);
                } else {
                    $hasRep = \Illuminate\Support\Facades\DB::table('product_merges')
                        ->where('master_name', $merge->master_name)
                        ->where('is_representative', true)
                        ->where('product_id', '!=', $merge->product_id)
                        ->exists();
                    $merge->is_representative = ! $hasRep;
                }
            }
        });

        static::deleted(function (ProductMerge $merge) {
            \Illuminate\Support\Facades\Cache::forget('product_merges_context');

            if ($merge->is_representative) {
                $remaining = \Illuminate\Support\Facades\DB::table('product_merges')
                    ->where('master_name', $merge->master_name)
                    ->pluck('product_id')
                    ->all();
                if (! empty($remaining)) {
                    sort($remaining);
                    $names = \Illuminate\Support\Facades\DB::table('products')
                        ->whereIn('id', $remaining)
                        ->pluck('name', 'id')
                        ->all();
                    $newRep = null;
                    foreach ($remaining as $pid) {
                        if (($names[$pid] ?? null) === $merge->master_name) {
                            $newRep = $pid;
                            break;
                        }
                    }
                    $newRep ??= $remaining[0];
                    \Illuminate\Support\Facades\DB::table('product_merges')
                        ->where('product_id', $newRep)
                        ->update(['is_representative' => true]);
                }
            }
        });

        static::saved(function () {
            \Illuminate\Support\Facades\Cache::forget('product_merges_context');
        });
    }
}
