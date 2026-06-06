<?php

namespace Modules\Region\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    protected $table = 'cities';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'province_id', 'nama', 'latitude', 'longitude'
    ];

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_id', 'id');
    }

    public function districts(): HasMany
    {
        return $this->hasMany(District::class, 'city_id', 'id');
    }
}
