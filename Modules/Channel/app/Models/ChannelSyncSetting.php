<?php

namespace Modules\Channel\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class ChannelSyncSetting extends Model
{
    use HasUuid7;

    protected $fillable = [
        'sync_enabled',
    ];

    protected $casts = [
        'sync_enabled' => 'boolean',
    ];
}
