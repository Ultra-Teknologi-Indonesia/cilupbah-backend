<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RackImportBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_no' => $this->batch_no,
            'state' => $this->state,
            'original_filename' => $this->original_filename,
            'total_rows' => $this->total_rows,
            'place_rows' => $this->place_rows,
            'manual_move_rows' => $this->manual_move_rows,
            'already_rows' => $this->already_rows,
            'error_rows' => $this->error_rows,
            'processed_rows' => $this->processed_rows,
            'success_rows' => $this->success_rows,
            'failed_rows' => $this->failed_rows,
            'progress_percent' => $this->progress_percent,
            'error_message' => $this->error_message,
            'executed_by' => $this->whenLoaded('executor', fn () => $this->executor?->name),
            'created_at' => $this->created_at,
        ];
    }
}
