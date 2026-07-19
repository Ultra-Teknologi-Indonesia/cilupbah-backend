<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Purchase\Enums\PurchaseActivityAction;
use App\Traits\HasUuid7;

class PurchaseOrderActivity extends Model
{
    use HasUuid7;

    protected $table = 'purchase_order_activities';

    public $timestamps = false;

    protected $fillable = [
        'purchase_order_id',
        'entity_type',
        'entity_id',
        'action_id',
        'action',
        'actor_id',
        'actor_name',
        'actor_email',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'action'     => PurchaseActivityAction::class,
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    public const ENTITY_ORDER = 'ORDER';
    public const ENTITY_ITEM  = 'ITEM';

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
