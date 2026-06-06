<?php

namespace Modules\Region\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class District extends Model
{
    protected $table = 'districts';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'city_id', 'nama', 'latitude', 'longitude'
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id', 'id');
    }

    public function villages(): HasMany
    {
        return $this->hasMany(Village::class, 'district_id', 'id');
    }
}
