<?php

declare(strict_types=1);

namespace Modules\Inventory\Exceptions;

use App\Exceptions\UserFacingException;

final class StockAdjustmentStockValidationException extends UserFacingException
{

    public function __construct(array $issues)
    {
        $messages = array_map(static function (array $issue): array {
            $identity = trim(implode(' · ', array_filter([
                $issue['sku'] ? 'SKU '.$issue['sku'] : null,
                $issue['rack_code'] ? 'Rak '.$issue['rack_code'] : null,
            ])));

            return [
                'message' => sprintf(
                    '%s tidak dapat disimpan. Stok di rak saat ini %d pcs, perubahan yang dimasukkan %+d pcs, sehingga stok menjadi %d pcs. Periksa jumlah penyesuaian atau pilih rak yang benar.',
                    $identity !== '' ? $identity : 'Baris penyesuaian',
                    $issue['current_on_hand'],
                    $issue['delta'],
                    $issue['resulting_on_hand'],
                ),
            ];
        }, $issues);
        $message = sprintf(
            'Penyesuaian stok belum disimpan. Ada %d baris yang perlu diperbaiki.',
            count($messages),
        );

        parent::__construct(
            title: 'Stok tidak mencukupi',
            message: $message,
            status: 422,
            errors: [
                'issue_count' => count($messages),
                'issues' => $messages,
            ],
        );
    }
}
