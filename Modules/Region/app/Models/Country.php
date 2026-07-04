<?php

namespace Modules\Region\Models;

use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    protected $table = 'countries';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'id', 'alpha2', 'alpha3', 'name',
    ];
}
