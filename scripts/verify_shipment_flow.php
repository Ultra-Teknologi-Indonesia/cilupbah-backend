<?php

use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\Location;
use Modules\Sales\Models\SalesOrder;
use Modules\Outbound\Models\Shipment;
use Modules\Outbound\Models\ShipmentOrder;
use Modules\Outbound\Services\ShipmentService;

$pass = 0; $fail = 0;
$check = function (string $label, bool $ok) use (&$pass, &$fail) {
    echo ($ok ? "  [PASS] " : "  [FAIL] ") . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

config(['queue.default' => 'sync']);

DB::beginTransaction();

try {
    echo PHP_EOL . "=== VERIFIKASI ALUR PENGIRIMAN (rollback di akhir) ===" . PHP_EOL;

    $kecil = Location::where('location_code', Location::SYSTEM_KECIL_CODE)->first();
    $check("Lokasi WH-KECIL tersedia", (bool) $kecil);
    if (! $kecil) { throw new \Exception('WH-KECIL tidak ada — hentikan.'); }

    $svc = app(ShipmentService::class);

    $seq = random_int(100000, 999999);
    $order = SalesOrder::create([
        'salesorder_no'      => 'VERIFY-' . $seq,
        'channel_order_no'   => 'VORD-' . $seq,
        'channel_shop_id'    => 'SHOP-TEST',
        'customer_name'      => 'Verify Buyer',
        'transaction_date'   => now(),
        'sub_total'          => 100000,
        'total_disc'         => 0,
        'total_tax'          => 0,
        'shipping_cost'      => 0,
        'insurance_cost'     => 0,
        'grand_total'        => 100000,
        'shipping_full_name' => 'Verify Buyer',
        'shipping_phone'     => '+628123456789',
        'shipping_address'   => 'Jl. Verify No. 1',
        'shipping_city'      => 'Jakarta',
        'shipping_province'  => 'DKI Jakarta',
        'shipping_post_code' => '12345',
        'shipping_country'   => 'ID',
        'status'             => 'packed',
        'is_paid'            => true,
        'is_canceled'        => false,
        'is_cod'             => true,          
        'source'             => 'shopee',
        'shipping_provider'  => 'J&T Express',
    ]);
    $check("Order dummy 'packed' dibuat (COD)", $order->status === 'packed');

    $shipment = $svc->create([
        'courier_name'  => 'J&T Express',
        'courier_code'  => 'jnt',
        'shipment_type' => 'REGULAR',
        'shipment_date' => now()->toDateString(),
        'created_by'    => 'verify@tinker',

    ]);
    $check("Manifest dibuat, lokasi auto-resolve ke WH-KECIL",
        $shipment->location_id === $kecil->id);
    $check("Status awal SCHEDULED", $shipment->status === Shipment::STATUS_SCHEDULED);

    $svc->addOrders($shipment->id, [$order->id]);
    $linked = ShipmentOrder::where('shipment_id', $shipment->id)
        ->where('order_id', $order->id)->exists();
    $check("Order masuk manifest (add-orders)", $linked);

    $svc->handOver($shipment->id);
    $shipment->refresh();
    $check("Selesaikan manifest → HANDED_OVER",
        $shipment->status === Shipment::STATUS_HANDED_OVER);

    $order->refresh();
    $order->channel_status = 'TO_CONFIRM_RECEIVE';
    $order->save();                       

    $order->refresh();
    $shipment->refresh();
    $check("received_date auto-stamp saat channel DELIVERED",
        ! empty($order->received_date));
    $check("Manifest auto-close → DELIVERED (syncFromChannelStatus)",
        $shipment->status === Shipment::STATUS_DELIVERED);

    $seq2 = random_int(100000, 999999);
    $order2 = $order->replicate();
    $order2->salesorder_no    = 'VERIFY2-' . $seq2;
    $order2->channel_order_no = 'VORD2-' . $seq2;
    $order2->channel_status   = null;
    $order2->received_date    = null;
    $order2->status           = 'packed';
    $order2->save();

    $shipment2 = $svc->create([
        'courier_name'  => 'J&T Express',
        'courier_code'  => 'jnt',
        'shipment_type' => 'REGULAR',
        'shipment_date' => now()->toDateString(),
        'created_by'    => 'verify@tinker',
    ]);
    $svc->addOrders($shipment2->id, [$order2->id]);

    $order2->refresh();
    $order2->channel_status = 'TO_CONFIRM_RECEIVE';
    $order2->save();
    $shipment2->refresh();
    $check("Celah urutan: manifest masih SCHEDULED saat delivered lebih dulu",
        $shipment2->status === Shipment::STATUS_SCHEDULED);

    $svc->handOver($shipment2->id);
    $shipment2->refresh();
    $check("Catch-up handOver → langsung DELIVERED",
        $shipment2->status === Shipment::STATUS_DELIVERED);

    echo PHP_EOL . "=== RINGKASAN: {$pass} PASS / {$fail} FAIL ===" . PHP_EOL;

} catch (\Throwable $e) {
    echo "  [ERROR] " . $e->getMessage() . PHP_EOL;
    echo "  di " . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
} finally {
    DB::rollBack();
    echo "=== ROLLBACK selesai — tidak ada data tersisa ===" . PHP_EOL;
}
