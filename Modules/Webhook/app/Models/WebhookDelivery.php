<?php

namespace Modules\Webhook\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    use HasUuid7;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'subscription_id',
        'event',
        'event_id',
        'payload',
        'status',
        'status_code',
        'attempts',
        'last_error',
        'delivered_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'status_code' => 'integer',
        'attempts' => 'integer',
        'delivered_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'subscription_id');
    }
}
