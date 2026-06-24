<?php

namespace Modules\Supplier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\HasUuid7;

class ContactCategory extends Model
{
    use HasUuid7;

    protected $fillable = [
        'code',
        'name',
        'description',
    ];

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'category_id');
    }
}
