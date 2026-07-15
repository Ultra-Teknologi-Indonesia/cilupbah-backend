<?php

namespace Modules\Warehouse\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class CompanyProfile extends Model
{
    use HasUuid7;

    protected $table = 'company_profile';

    protected $fillable = [
        'legal_name',
        'address',
        'city',
        'postal_code',
        'phone',
        'email',
        'logo_media_id',
        'signature_media_id',
    ];
}
