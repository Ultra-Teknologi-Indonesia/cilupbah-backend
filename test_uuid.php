<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$inbound = Modules\Inbound\Models\InboundItem::first();
if ($inbound) {
    echo "ID: " . $inbound->id . " (Length: " . strlen($inbound->id) . ")\n";
} else {
    echo "No inbound item found.\n";
}
