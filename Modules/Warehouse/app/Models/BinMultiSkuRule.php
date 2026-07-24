<?php

namespace Modules\Warehouse\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Warehouse\Services\BinMultiSkuRuleService;

class BinMultiSkuRule extends Model
{
    use HasUuid7;

    protected $fillable = [
        'location_id',
        'pattern',
        'note',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        $flush = fn () => BinMultiSkuRuleService::flushPatternCache();

        static::saved($flush);
        static::deleted($flush);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
