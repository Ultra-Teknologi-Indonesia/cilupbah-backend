<?php

namespace Modules\Warehouse\Repositories;

use Modules\Warehouse\Models\CompanyProfile;

class CompanyProfileRepository
{
    public function current(): CompanyProfile
    {
        return CompanyProfile::query()->firstOrCreate([], [
            'legal_name' => 'PT ULTRA TEKNOLOGI INDONESIA',
            'brand_name' => 'Cilupbah',
        ]);
    }

    public function update(array $data): CompanyProfile
    {
        $profile = $this->current();
        $profile->update($data);

        return $profile->refresh();
    }
}
