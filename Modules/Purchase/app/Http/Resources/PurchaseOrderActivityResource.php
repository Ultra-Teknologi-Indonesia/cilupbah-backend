<?php

namespace Modules\Purchase\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $metadata = $this->metadata ?? [];

        return [
            'id'          => $this->id,
            'entity_type' => $this->entity_type,
            'entity_id'   => $this->entity_id,
            'action'      => $this->action->value,
            'action_id'   => $this->action_id,
            'label'       => $this->action->label(),
            'actor_id'    => $this->actor_id,
            'actor_name'  => $this->actor_name,
            'actor_email' => $this->actor_email,
            'entity_no'   => $metadata['entity_no'] ?? null,
            'note'        => $metadata['note'] ?? null,
            'qty'         => $metadata['qty'] ?? null,
            'prev_values' => $metadata['prev_values'] ?? null,
            'new_values'  => $metadata['new_values'] ?? null,
            'created_at'  => $this->created_at?->toIso8601String(),
        ];
    }
}
