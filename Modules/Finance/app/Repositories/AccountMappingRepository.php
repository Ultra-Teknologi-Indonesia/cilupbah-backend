<?php

namespace Modules\Finance\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Finance\Models\AccountMapping;

class AccountMappingRepository
{

    public function allWithAccount(): Collection
    {
        return AccountMapping::with('account')->get();
    }

    public function findByKey(string $key): ?AccountMapping
    {
        return AccountMapping::with('account')->where('key', $key)->first();
    }

    public function upsert(string $key, ?string $accountId): AccountMapping
    {
        return AccountMapping::updateOrCreate(
            ['key' => $key],
            ['account_id' => $accountId],
        );
    }
}
