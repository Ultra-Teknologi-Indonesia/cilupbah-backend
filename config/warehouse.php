<?php

return [
    'unassign_roles' => [
        'primary'  => ['owner', 'admin', 'kepala gudang'],
        'inbound'  => ['leader inbound'],
        'putaway'  => ['leader inbound'],
        'picking'  => ['leader outbound'],
        'reset_destructive' => ['owner', 'admin'],
        'reset_picking'     => ['owner', 'admin', 'kepala gudang'],
    ],
];
