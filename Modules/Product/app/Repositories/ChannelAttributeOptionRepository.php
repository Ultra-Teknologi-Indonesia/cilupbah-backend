<?php

namespace Modules\Product\Repositories;

use Modules\Product\Models\ChannelAttributeOption;

class ChannelAttributeOptionRepository
{
    public function upsert(array $data, array $uniqueBy, array $updateColumns = null): void
    {
        ChannelAttributeOption::upsert($data, $uniqueBy, $updateColumns);
    }
}
