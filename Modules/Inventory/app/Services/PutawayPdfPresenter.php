<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Modules\Inventory\Models\Putaway;

class PutawayPdfPresenter
{
    public function __construct(protected PutawayService $putawayService) {}

    public function present(Putaway $putaway): array
    {
        $putaway = $this->putawayService->loadForPdf($putaway);

        return [
            'putaway' => $putaway,
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
