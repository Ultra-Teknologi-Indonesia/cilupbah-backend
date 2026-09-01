<?php

declare(strict_types=1);

namespace Modules\Outbound\Tests\Unit;

use Illuminate\Database\Eloquent\Collection;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Outbound\Models\Shipment;
use Modules\Outbound\Models\ShipmentOrder;
use Modules\Outbound\Support\ProcessOrderStatusResolver;
use Modules\Sales\Models\SalesOrder;
use PHPUnit\Framework\TestCase;

final class ProcessOrderStatusResolverTest extends TestCase
{
    private ProcessOrderStatusResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ProcessOrderStatusResolver;
    }

    public function test_reserved_order_without_picklist_is_picking_not_started(): void
    {
        $order = $this->order([
            'status' => 'reserved',
            'handed_to_warehouse_at' => now(),
        ]);
        $order->setRelation('picklistItems', new Collection);

        $this->assertSame([
            'stage' => 'Picking',
            'sub_status' => 'Belum Mulai',
        ], $this->resolver->resolve($order));
    }

    public function test_reserved_order_with_active_picklist_is_picking_in_progress(): void
    {
        $picklist = (new Picklist)->setAttribute('status', Picklist::STATUS_IN_PROGRESS);
        $picklistItem = (new PicklistItem)->setRelation('picklist', $picklist);
        $order = $this->order(['status' => 'reserved']);
        $order->setRelation('picklistItems', new Collection([$picklistItem]));

        $this->assertSame('Diproses', $this->resolver->resolve($order)['sub_status']);
    }

    public function test_picked_order_with_active_packlist_is_packing_in_progress(): void
    {
        $order = $this->order(['status' => 'picked']);
        $order->setRelation('packlist', (new Packlist)->setAttribute('status', Packlist::STATUS_DRAFT));

        $this->assertSame([
            'stage' => 'Packing',
            'sub_status' => 'Diproses',
        ], $this->resolver->resolve($order));
    }

    public function test_packed_order_without_shipment_is_ready_to_ship(): void
    {
        $order = $this->order(['status' => 'packed']);
        $order->setRelation('shipmentOrders', new Collection);

        $this->assertSame([
            'stage' => 'Shipping',
            'sub_status' => 'Siap Kirim',
        ], $this->resolver->resolve($order));
    }

    public function test_shipped_order_is_completed_only_after_received_date(): void
    {
        $order = $this->order([
            'status' => 'shipped',
            'received_date' => now(),
        ]);

        $this->assertSame('Selesai', $this->resolver->resolve($order)['stage']);
    }

    public function test_packed_order_with_scheduled_shipment_is_shipping_schedule(): void
    {
        $shipment = (new Shipment)->setAttribute('status', Shipment::STATUS_SCHEDULED);
        $shipmentOrder = (new ShipmentOrder)->setRelation('shipment', $shipment);
        $order = $this->order(['status' => 'packed']);
        $order->setRelation('shipmentOrders', new Collection([$shipmentOrder]));

        $this->assertSame('Jadwal Pengiriman', $this->resolver->resolve($order)['sub_status']);
    }

    public function test_cancelled_order_with_completed_packlist_is_packing_complete(): void
    {
        $order = $this->order(['status' => 'cancelled']);
        $order->setRelation(
            'packlist',
            (new Packlist)->setAttribute('status', Packlist::STATUS_COMPLETED),
        );
        $order->setRelation('shipmentOrders', new Collection);

        $this->assertSame([
            'stage' => 'Packing',
            'sub_status' => 'Selesai',
        ], $this->resolver->resolve($order));
    }

    public function test_cancelled_order_with_scheduled_shipment_is_shipping_schedule(): void
    {
        $shipment = (new Shipment)->setAttribute('status', Shipment::STATUS_SCHEDULED);
        $shipmentOrder = (new ShipmentOrder)->setRelation('shipment', $shipment);
        $order = $this->order(['status' => 'cancelled']);
        $order->setRelation('packlist', null);
        $order->setRelation('shipmentOrders', new Collection([$shipmentOrder]));

        $this->assertSame([
            'stage' => 'Shipping',
            'sub_status' => 'Jadwal Pengiriman',
        ], $this->resolver->resolve($order));
    }

    private function order(array $attributes): SalesOrder
    {
        $order = new SalesOrder;
        $order->setRawAttributes($attributes);

        return $order;
    }
}
