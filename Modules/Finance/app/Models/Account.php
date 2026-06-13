<?php

namespace Modules\Finance\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use HasUuid7;

    protected $fillable = [
        'account_code',
        'account_name',
        'account_type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function getDisplayNameAttribute(): string
    {
        return $this->account_code . ' - ' . $this->account_name;
    }

    public function journalDetails(): HasMany
    {
        return $this->hasMany(JournalDetail::class);
    }
}
