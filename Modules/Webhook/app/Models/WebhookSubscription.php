<?php

namespace Modules\Webhook\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookSubscription extends Model
{
    use HasUuid7;

    protected $fillable = [
        'event',
        'target_url',
        'secret',
        'is_active',
        'consecutive_failures',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'consecutive_failures' => 'integer',
    ];

    protected $hidden = [
        'secret',
    ];

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'subscription_id');
    }
}
