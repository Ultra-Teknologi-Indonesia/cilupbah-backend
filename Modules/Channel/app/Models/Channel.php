<?php

namespace Modules\Channel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

use App\Traits\HasUuid7;

class Channel extends Model
{
    use HasUuid7;
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
