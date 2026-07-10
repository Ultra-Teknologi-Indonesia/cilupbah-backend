<?php

namespace Modules\Purchase\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReturnResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
