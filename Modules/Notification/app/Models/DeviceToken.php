<?php

namespace Modules\Notification\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid7;

class DeviceToken extends Model
{
    use HasUuid7;

    protected $fillable = [
        'user_id',
        'fcm_token',
        'device_id',
        'platform',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
