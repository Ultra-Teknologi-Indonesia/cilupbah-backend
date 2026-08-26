<?php

namespace Modules\Outbound\Data;

use Modules\Outbound\Models\Shipment;
use Modules\Outbound\Models\ShipmentOrder;

final readonly class ShipmentScanResult
{
    public function __construct(
        public Shipment $shipment,
        public ShipmentOrder $shipmentOrder,
        public bool $alreadyAdded,
        public string $barcode,
    ) {}
}
