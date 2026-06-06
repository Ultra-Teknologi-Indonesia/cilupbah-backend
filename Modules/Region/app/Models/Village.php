<?php

namespace Modules\Region\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Village extends Model
{
    protected $table = 'villages';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'district_id', 'nama', 'latitude', 'longitude'
    ];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id', 'id');
    }
}
