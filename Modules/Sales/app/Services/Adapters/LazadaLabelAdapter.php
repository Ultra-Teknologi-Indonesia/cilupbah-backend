<?php

namespace Modules\Sales\Services\Adapters;

use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;

class LazadaLabelAdapter implements ChannelLabelAdapter
{
    public function __construct(private SalesOrderService $salesOrderService)
    {
    }

    public function fetchLabel(SalesOrder $order, array $options): array
    {
        return $this->salesOrderService->getShippingLabel($order, $options);
    }

    public function channel(): string
    {
        return 'lazada';
    }
}
