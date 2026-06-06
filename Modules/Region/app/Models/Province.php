<?php

namespace Modules\Region\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Province extends Model
{
    protected $table = 'provinces';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'nama', 'latitude', 'longitude'
    ];

    public function cities(): HasMany
    {
        return $this->hasMany(City::class, 'province_id', 'id');
    }
}
