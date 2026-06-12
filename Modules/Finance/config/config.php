<?php

return [
    'name' => 'Finance',

    /*
    | Pemetaan payment_method → akun kas/bank (setara account di Jubelio).
    | Sementara berbasis config karena Chart of Accounts belum dibangun (domain Journal).
    | Saat COA ada, map ini diganti tabel — kontrak response cashbank tidak berubah.
    */
    'cashbank_accounts' => [
        'cash' => ['id' => '1-1000', 'name' => '1-1000 - Kas'],
        'tunai' => ['id' => '1-1000', 'name' => '1-1000 - Kas'],
        'transfer' => ['id' => '1-1001', 'name' => '1-1001 - Bank'],
        'bank' => ['id' => '1-1001', 'name' => '1-1001 - Bank'],
        'bank_transfer' => ['id' => '1-1001', 'name' => '1-1001 - Bank'],
        'giro' => ['id' => '1-1002', 'name' => '1-1002 - Giro'],
    ],

    // Fallback bila payment_method tidak dikenal / kosong.
    'cashbank_default_account' => ['id' => '1-1000', 'name' => '1-1000 - Kas'],

    // Akun lawan untuk jurnal sintetis (sebelum ada GL nyata).
    'counter_accounts' => [
        'receivable' => ['id' => '1-1100', 'name' => '1-1100 - Piutang Usaha'],
        'payable' => ['id' => '2-2000', 'name' => '2-2000 - Hutang Usaha'],
    ],
];
