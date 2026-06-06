<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
use App\Traits\HasUuid7;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use HasUuid7;

    protected $keyType = 'string';
    public $incrementing = false;
}
