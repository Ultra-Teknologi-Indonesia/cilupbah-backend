<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;
use App\Traits\HasUuid7;

class Role extends SpatieRole
{
    use HasUuid7;

    protected $keyType = 'string';
    public $incrementing = false;
}
