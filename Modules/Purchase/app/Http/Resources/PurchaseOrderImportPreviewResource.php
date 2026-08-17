<?php

namespace Modules\Purchase\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderImportPreviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'token'     => $this->resource['token'] ?? null,
            'summary'   => $this->resource['summary'] ?? [],
            'documents' => $this->resource['documents'] ?? [],
            'errors'    => $this->resource['errors'] ?? [],
            'warnings'  => $this->resource['warnings'] ?? [],
        ];
    }
}
