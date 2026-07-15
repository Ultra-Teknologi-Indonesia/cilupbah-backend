<?php

namespace App\Exceptions;

use Throwable;

class InboundSessionClosedException extends UserFacingException
{
    public function __construct(
        ?string $inboundNumber = null,
        ?Throwable $previous = null,
    ) {
        $ref = $inboundNumber ? " ({$inboundNumber})" : '';
        parent::__construct(
            title: 'Sesi penerimaan sudah ditutup',
            message: "Sesi penerimaan{$ref} sudah selesai — tidak bisa menambah receipt dari mobile. Hubungi admin untuk koreksi via web atau buat penerimaan susulan.",
            status: 409,
            errors: [
                'code' => 'INBOUND_SESSION_CLOSED',
                'inbound_number' => $inboundNumber,
            ],
            previous: $previous,
        );
    }
}
