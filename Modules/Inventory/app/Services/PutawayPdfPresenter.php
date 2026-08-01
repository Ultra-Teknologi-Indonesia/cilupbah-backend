<?php

namespace Modules\Inventory\Services;

use App\Services\QrCodeGenerator;
use Modules\Inventory\Models\Putaway;

class PutawayPdfPresenter
{
    public function __construct(
        protected PutawayService $putawayService,
        protected QrCodeGenerator $qrCode,
    ) {}

    public function present(Putaway $putaway): array
    {
        $putaway = $this->putawayService->loadForPdf($putaway);

        return [
            'putaway' => $putaway,
            'qrDataUri' => $this->qrCode->svgDataUri((string) ($putaway->putaway_no ?? 'PUT')),
            'sourceLabel' => $this->putawayService->resolvePdfSourceLabel($putaway),
        ];
    }

    public function presentMany(iterable $putaways): array
    {
        $docs = [];

        foreach ($putaways as $putaway) {
            $docs[] = $this->present($putaway);
        }

        return $docs;
    }
}
