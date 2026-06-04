<?php

namespace Modules\Channel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    public function shops(): HasMany
    {
        return $this->hasMany(ChannelShop::class);
    }
}
