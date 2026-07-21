<?php

use Modules\Sales\Models\SalesOrder;
use Modules\Outbound\Support\InstantOrderClassifier;

$IDS = [

];

$q = SalesOrder::query()->where('source', 'shopee');

if (! empty($IDS)) {
    $q->where(function ($w) use ($IDS) {
        $w->whereIn('channel_order_no', $IDS)
          ->orWhereIn('tracking_number', $IDS)
          ->orWhereIn('salesorder_no', $IDS);
    });
} else {
    $q->where('driver_call_status', 'failed')
      ->orderByDesc('driver_call_attempted_at');
}

$orders = $q->limit(15)->get();

if ($orders->isEmpty()) {
    echo "Tidak ada order cocok / tidak ada Shopee driver_call_status=failed.\n";
    return;
}

foreach ($orders as $o) {
    echo str_repeat('=', 74) . PHP_EOL;
    echo "No. Pesanan    : {$o->salesorder_no}\n";
    echo "channel_order  : {$o->channel_order_no}\n";
    echo "tracking_no    : " . ($o->tracking_number ?: '— (kosong)') . "\n";
    echo "shop_id        : " . ($o->channel_shop_id ?: '— (kosong)') . "\n";
    echo "status internal: {$o->status}\n";
    echo "channel_status : " . ($o->channel_status ?: '—') . "   <== RETRY_SHIP? READY_TO_SHIP?\n";
    echo "ship_provider  : " . ($o->shipping_provider ?: '—') . " | type: " . ($o->shipping_type ?: '—') . "\n";
    echo "instant?       : " . (InstantOrderClassifier::isInstant($o->shipping_provider, $o->shipping_type) ? 'YA (instant/same-day)' : 'tidak (reguler)') . "\n";
    echo "driver_status  : " . ($o->driver_call_status ?: '—') . "\n";
    echo "driver_message : " . ($o->driver_call_message ?: '—') . "\n";
    echo "attempted_at   : " . ($o->driver_call_attempted_at ?: '—') . "\n";

    $resp = $o->driver_call_response;
    if (is_array($resp) && ! empty($resp)) {
        echo "raw response   :\n";
        echo "  " . json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo "raw response   : — (tidak tersimpan)\n";
    }
}

echo str_repeat('=', 74) . PHP_EOL;
echo "Total: {$orders->count()} order.\n";
echo "\nPetunjuk baca:\n";
echo "- CATATAN: Shopee RETRY_SHIP dinormalisasi jadi PROCESSED di channel_status.\n";
echo "  Jadi RETRY_SHIP TIDAK muncul di sini — lihat driver_message untuk indikasinya.\n";
echo "- 'Package is not ready to ship'  → order sudah CANCELLED / bukan state bisa-ship.\n";
echo "- 'System error, try again later' → error transient Shopee, ulangi beberapa saat lagi.\n";
echo "- 'tracking number is invalid'    → order belum benar-benar READY_TO_SHIP saat dipanggil.\n";
echo "- 'Label belum siap'              → AWB Shopee di-generate asinkron; tunggu lalu ulang.\n";
