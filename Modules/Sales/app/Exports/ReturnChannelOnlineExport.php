<?php

namespace Modules\Sales\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Modules\Sales\Exports\Sheets\ReturnChannelOnlineSheet;
use Modules\Sales\Services\SalesReturnService;

class ReturnChannelOnlineExport implements WithMultipleSheets
{
    public function __construct(
        private readonly ?string $dateFrom,
        private readonly ?string $dateTo,
        private readonly ?string $locationId,
    ) {}

    public function sheets(): array
    {
        $data = app(SalesReturnService::class)->buildChannelOnlinePutawayReport(
            $this->dateFrom,
            $this->dateTo,
            $this->locationId,
        );

        return [
            new ReturnChannelOnlineSheet($data['placed'], 'Sudah Ditempatkan'),
            new ReturnChannelOnlineSheet($data['unplaced'], 'Belum Ditempatkan'),
        ];
    }
}
