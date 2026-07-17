<?php

$THERMAL_SIZES = [
    ['value' => 'thermal_100x150', 'label' => '10×15 cm (Thermal)', 'default' => true],
    ['value' => 'thermal_100x120', 'label' => '10×12 cm (Thermal)'],
];

return [

    'tiktok' => [
        'document_types' => [],
        'document_sizes' => $THERMAL_SIZES,
    ],

    'shopee' => [
        'document_types' => [],
        'document_sizes' => $THERMAL_SIZES,
    ],

    'lazada' => [
        'document_types' => [],
        'document_sizes' => $THERMAL_SIZES,
    ],

    'woocommerce' => [
        'document_types' => [],
        'document_sizes' => [],
    ],

];
