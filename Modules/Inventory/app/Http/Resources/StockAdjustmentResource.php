<?php

namespace Modules\Inventory\Http\Resources;

use App\Support\ActorName;
use Illuminate\Http\Resources\Json\JsonResource;

class StockAdjustmentResource extends JsonResource
{
    public function toArray($request): array
    {
        $data = parent::toArray($request);
        $data['created_by'] = ActorName::resolve($this->created_by);

        return $data;
    }
}
