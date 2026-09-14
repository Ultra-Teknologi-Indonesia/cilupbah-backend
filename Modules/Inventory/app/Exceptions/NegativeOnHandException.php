<?php

declare(strict_types=1);

namespace Modules\Inventory\Exceptions;

use App\Exceptions\UserFacingException;
use Throwable;

final class NegativeOnHandException extends UserFacingException
{
    public function __construct(
        int $currentOnHand,
        int $delta,
        string $operation,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        $result = $currentOnHand + $delta;
        $sku = isset($context['sku']) ? trim((string) $context['sku']) : null;
        $rackCode = isset($context['rack_code']) ? trim((string) $context['rack_code']) : null;
        $subject = match (true) {
            $sku !== null && $sku !== '' && $rackCode !== null && $rackCode !== '' => " untuk SKU {$sku} di rak {$rackCode}",
            $sku !== null && $sku !== '' => " untuk SKU {$sku}",
            $rackCode !== null && $rackCode !== '' => " di rak {$rackCode}",
            default => '',
        };

        parent::__construct(
            title: 'Stok fisik tidak mencukupi',
            message: "{$operation}{$subject} dibatalkan karena saldo stok fisik akan menjadi {$result}. Saldo on hand tidak boleh kurang dari 0.",
            status: 422,
            errors: array_filter([
                'sku' => $sku,
                'rack_code' => $rackCode,
                'current_on_hand' => $currentOnHand,
                'delta' => $delta,
                'resulting_on_hand' => $result,
                'operation' => $operation,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            previous: $previous,
        );
    }
}
