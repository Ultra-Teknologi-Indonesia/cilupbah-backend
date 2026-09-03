<?php

declare(strict_types=1);

namespace Modules\Inventory\Exceptions;

use App\Exceptions\UserFacingException;

final class NegativeOnHandException extends UserFacingException
{
    public function __construct(
        int $currentOnHand,
        int $delta,
        string $operation,
    ) {
        $result = $currentOnHand + $delta;

        parent::__construct(
            title: 'Stok fisik tidak mencukupi',
            message: "{$operation} dibatalkan karena saldo stok fisik akan menjadi {$result}. Saldo on hand tidak boleh kurang dari 0.",
            status: 422,
            errors: [
                'current_on_hand' => $currentOnHand,
                'delta' => $delta,
                'resulting_on_hand' => $result,
                'operation' => $operation,
            ],
        );
    }
}
