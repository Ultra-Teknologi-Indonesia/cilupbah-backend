<?php

declare(strict_types=1);

namespace Modules\Sales\Repositories;

use Modules\Sales\Enums\SalesOrderStatus;
use Modules\Sales\Models\BulkShippingLabelItem;

final class BulkShippingLabelCleanupRepository
{
    public function eachCompletedWithPdf(callable $callback, int $chunkSize = 100): void
    {
        $statuses = [
            SalesOrderStatus::SHIPPED->value,
            SalesOrderStatus::CANCELLED->value,
            SalesOrderStatus::RETURNED->value,
        ];

        BulkShippingLabelItem::query()
            ->whereNotNull('ready_pdf_path')
            ->whereHas('order', fn ($query) => $query->whereIn('status', $statuses))
            ->chunkById(max(1, min(500, $chunkSize)), $callback);
    }

    public function clearPdfPaths(BulkShippingLabelItem $item): void
    {
        $item->update([
            'ready_pdf_path' => null,
            'raw_pdf_path' => null,
        ]);
    }
}
