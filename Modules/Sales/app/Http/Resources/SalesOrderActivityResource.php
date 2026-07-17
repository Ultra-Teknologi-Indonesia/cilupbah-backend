<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sales\Enums\OrderActivityAction;
use Modules\Sales\Enums\OrderActivityEntity;

class SalesOrderActivityResource extends JsonResource
{
    public function toArray($request): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];

        $action = $this->action instanceof OrderActivityAction ? $this->action : null;
        $entity = $this->entity_type instanceof OrderActivityEntity ? $this->entity_type : null;

        return [
            'id'           => $this->id,
            'action_date'  => optional($this->created_at)->toIso8601String(),
            'email'        => $this->actor_email ?? 'system',
            'actor_name'   => $this->actor_name,
            'entity_type'  => $entity?->value ?? 'ORDER',
            'entity_id'    => $this->entity_id,
            'entity_no'    => $metadata['entity_no'] ?? optional($this->order)->salesorder_no,
            'action'       => $this->resolveAction($action, $metadata),
            'action_id'    => $this->action_id,
            'action_label' => $action?->value ?? (string) $this->action,
            'note'         => $metadata['note'] ?? null,
            'prev_values'  => $metadata['prev_values'] ?? null,
            'new_values'   => $metadata['new_values'] ?? null,
        ];
    }

    private function resolveAction(?OrderActivityAction $action, array $metadata): string
    {
        return match (true) {
            $action === OrderActivityAction::CREATED,
            $action === OrderActivityAction::ITEM_CREATED => 'C',
            $action === OrderActivityAction::CANCELLED && empty($metadata['prev_values']) => 'D',
            default => 'U',
        };
    }
}
