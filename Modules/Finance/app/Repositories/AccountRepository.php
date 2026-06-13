<?php

namespace Modules\Finance\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Finance\Models\Account;

class AccountRepository
{

    public function getActiveLookup(): Collection
    {
        return Account::where('is_active', true)
            ->orderBy('account_code')
            ->get();
    }

    public function findByCode(string $code): ?Account
    {
        return Account::where('account_code', $code)->first();
    }
}
