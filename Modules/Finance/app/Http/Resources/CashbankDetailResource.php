<?php

namespace Modules\Finance\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashbankDetailResource extends JsonResource
{

    public function __construct(Model $payment, protected array $ctx)
    {
        parent::__construct($payment);
    }

    public function toArray(Request $request): array
    {
        return [
            'payment_id' => $this->id,
            'payment_no' => $this->payment_number,
            'payment_type' => $this->ctx['doc_type'],
            'amount' => (string) $this->amount,
            'cashbank_account_id' => $this->ctx['account']['id'],
            'cashbank_account_name' => $this->ctx['account']['name'],
            'contact_id' => $this->ctx['contact_id'],
            'contact_name' => $this->ctx['contact_name'],
            'note' => $this->notes ?? '',
            'reference_no' => $this->reference_no,
            'transaction_date' => $this->payment_date?->toIso8601String(),
            'accounts' => $this->ctx['lines'],
        ];
    }
}
