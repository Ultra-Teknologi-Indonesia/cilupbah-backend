<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;
use App\Traits\HasUuid7;

class Permission extends SpatiePermission
{
    use HasUuid7;

    protected $keyType = 'string';
    public $incrementing = false;
}
