<?php

namespace App\Exceptions;

use Throwable;

class PutawayActiveException extends UserFacingException
{
    public function __construct(
        array $activePutawayNumbers = [],
        ?Throwable $previous = null,
    ) {
        $list = empty($activePutawayNumbers) ? '' : ' (' . implode(', ', $activePutawayNumbers) . ')';

        parent::__construct(
            title: 'Ada putaway aktif',
            message: "Tidak dapat reset penerimaan karena masih ada putaway aktif{$list}. Selesaikan atau cancel putaway dulu.",
            status: 422,
            errors: [
                'code' => 'PUTAWAY_ACTIVE',
                'active_putaways' => $activePutawayNumbers,
            ],
            previous: $previous,
        );
    }
}
