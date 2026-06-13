<?php

return [
    'name' => 'Finance',

    'cashbank_accounts' => [
        'cash' => ['id' => '1-1000', 'name' => '1-1000 - Kas'],
        'tunai' => ['id' => '1-1000', 'name' => '1-1000 - Kas'],
        'transfer' => ['id' => '1-1001', 'name' => '1-1001 - Bank'],
        'bank' => ['id' => '1-1001', 'name' => '1-1001 - Bank'],
        'bank_transfer' => ['id' => '1-1001', 'name' => '1-1001 - Bank'],
        'giro' => ['id' => '1-1002', 'name' => '1-1002 - Giro'],
    ],

    'cashbank_default_account' => ['id' => '1-1000', 'name' => '1-1000 - Kas'],

    'counter_accounts' => [
        'receivable' => ['id' => '1-1100', 'name' => '1-1100 - Piutang Usaha'],
        'payable' => ['id' => '2-2000', 'name' => '2-2000 - Hutang Usaha'],
    ],
];
