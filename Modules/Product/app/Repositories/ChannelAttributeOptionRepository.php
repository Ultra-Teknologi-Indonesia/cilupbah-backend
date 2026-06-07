<?php

namespace Modules\Product\Repositories;

use Modules\Product\Models\ChannelAttributeOption;

class ChannelAttributeOptionRepository
{
    public function upsert(array $data, array $uniqueBy): void
    {
        ChannelAttributeOption::upsert($data, $uniqueBy);
    }
}
