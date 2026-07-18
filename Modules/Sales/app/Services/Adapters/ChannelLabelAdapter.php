<?php

namespace Modules\Sales\Services\Adapters;

use Modules\Sales\Models\SalesOrder;

interface ChannelLabelAdapter
{

    public function fetchLabel(SalesOrder $order, array $options): array;

    public function channel(): string;
}
