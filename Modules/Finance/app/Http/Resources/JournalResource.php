<?php

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'journal_id' => $this->id,
            'journal_no' => $this->journal_no,
            'journal_type' => $this->journal_type,
            'transaction_date' => $this->transaction_date?->toIso8601String(),
            'source_doc_no' => $this->source_doc_no,
            'notes' => $this->notes ?? '',
            'debit' => (string) $this->total_debit,
            'credit' => (string) $this->total_credit,
        ];
    }
}
