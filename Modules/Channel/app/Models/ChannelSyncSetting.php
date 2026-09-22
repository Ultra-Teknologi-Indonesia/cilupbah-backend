<?php

namespace Modules\Channel\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class ChannelSyncSetting extends Model
{
    use HasUuid7;

    protected $fillable = [
        'sync_enabled',
        'paused_at',
        'resumed_at',
        'auto_paused_on',
        'pause_reason',
    ];

    protected $casts = [
        'sync_enabled' => 'boolean',
        'paused_at' => 'immutable_datetime',
        'resumed_at' => 'immutable_datetime',
        'auto_paused_on' => 'immutable_date',
    ];
}
