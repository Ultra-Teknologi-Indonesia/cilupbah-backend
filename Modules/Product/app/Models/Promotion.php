<?php

namespace Modules\Product\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promotion extends Model
{
    use HasUuid7;

    public const TYPE_DISCOUNT_PERCENTAGE = 'DISCOUNT_PERCENTAGE';
    public const TYPE_DISCOUNT_AMOUNT = 'DISCOUNT_AMOUNT';
    public const TYPES = [self::TYPE_DISCOUNT_PERCENTAGE, self::TYPE_DISCOUNT_AMOUNT];

    protected $fillable = [
        'name',
        'type',
        'value',
        'min_qty',
        'start_date',
        'end_date',
        'is_active',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_qty' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PromotionItem::class);
    }
}
