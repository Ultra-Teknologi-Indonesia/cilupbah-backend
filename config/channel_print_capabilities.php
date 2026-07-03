<?php

return [

    'tiktok' => [
        'document_types' => [
            ['value' => 'SHIPPING_LABEL', 'label' => 'Label', 'default' => true],
            ['value' => 'PACKING_LIST', 'label' => 'Slip'],
            ['value' => 'SHIPPING_LABEL_AND_PACKING_LIST', 'label' => 'Label + Slip'],
        ],
        'document_sizes' => [
            ['value' => 'A6', 'label' => 'A6 / Thermal', 'default' => true],
            ['value' => 'A5', 'label' => 'A5'],
            ['value' => 'A4', 'label' => 'A4'],
        ],
    ],

    'shopee' => [
        'document_types' => [
            ['value' => 'NORMAL_AIR_WAYBILL', 'label' => 'AWB Normal', 'default' => true],
            ['value' => 'THERMAL_AIR_WAYBILL', 'label' => 'AWB Thermal'],
        ],
        'document_sizes' => [],
    ],

];
