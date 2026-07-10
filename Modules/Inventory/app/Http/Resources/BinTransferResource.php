<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BinTransferResource extends JsonResource
{
    public function toArray($request): array
    {
        return parent::toArray($request);
    }
}
