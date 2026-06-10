<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;

class ArchiveItemResource extends MasterItemResource
{
    public function toArray(Request $request): array
    {
        $archivedBy = $this->resource->relationLoaded('archivedBy') ? $this->archivedBy : null;

        return parent::toArray($request) + [
            'archived_at' => $this->archived_at,
            'archived_by' => $archivedBy->email ?? null,
            'archive_reason' => $this->archive_reason,
        ];
    }
}
