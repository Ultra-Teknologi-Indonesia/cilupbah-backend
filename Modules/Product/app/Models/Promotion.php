<?php

namespace Modules\Product\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promotion extends Model
{
    use HasUuid7;

    public const TYPE_PERCENT = 'percent';
    public const TYPE_FIXED = 'fixed';
    public const TYPES = [self::TYPE_PERCENT, self::TYPE_FIXED];

    protected $fillable = [
        'name',
        'type',
        'value',
        'start_at',
        'end_at',
        'is_active',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PromotionItem::class);
    }
}
