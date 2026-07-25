<?php

namespace Modules\Inventory\Http\Resources;

use App\Support\ActorName;
use Illuminate\Http\Resources\Json\JsonResource;

class StockRevaluationResource extends JsonResource
{
    public function toArray($request): array
    {
        $data = parent::toArray($request);
        if (array_key_exists('approved_by', $data)) {
            $data['approved_by'] = ActorName::resolve($data['approved_by']);
        }

        return $data;
    }
}
