<?php

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'journal_detail_id' => $this->id,
            'account_id' => $this->account_id,
            'account_name' => $this->account?->display_name,
            'debit' => (string) $this->debit,
            'credit' => (string) $this->credit,
            'description' => $this->description,
        ];
    }
}
