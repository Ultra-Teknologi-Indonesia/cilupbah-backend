<?php

return [
    'allow_negative_stock' => env('INVENTORY_ALLOW_NEGATIVE_STOCK', true),

    'channel_auto_physical_backfill' => env('INVENTORY_CHANNEL_AUTO_PHYSICAL_BACKFILL', false),
];
