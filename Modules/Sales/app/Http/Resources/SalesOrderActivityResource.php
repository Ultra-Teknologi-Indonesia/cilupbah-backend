<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SalesOrderActivityResource extends JsonResource
{
    public function toArray($request): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];

        return [
            'id'           => $this->id,
            'action_date'  => optional($this->created_at)->toIso8601String(),
            'email'        => $this->actor_email ?? 'system',
            'actor_name'   => $this->actor_name,
            'entity_no'    => $metadata['entity_no'] ?? optional($this->order)->salesorder_no,
            'action'       => $this->resolveAction($metadata),
            'action_id'    => $this->action_id,
            'action_label' => $this->action,
            'note'         => $metadata['note'] ?? null,
            'prev_values'  => $metadata['prev_values'] ?? null,
            'new_values'   => $metadata['new_values'] ?? null,
        ];
    }

    private function resolveAction(array $metadata): string
    {
        if ($this->action === 'CREATED') {
            return 'C';
        }
        if ($this->action === 'CANCELLED' && empty($metadata['prev_values'])) {
            return 'D';
        }
        return 'U';
    }
}
