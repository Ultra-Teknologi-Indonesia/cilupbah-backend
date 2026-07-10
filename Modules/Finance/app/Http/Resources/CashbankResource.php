<?php

namespace Modules\Finance\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashbankResource extends JsonResource
{

    public function __construct(Model $payment, protected array $ctx)
    {
        parent::__construct($payment);
    }

    public function toArray(Request $request): array
    {
        return [
            'account_id' => $this->ctx['account']['id'],
            'account_name' => $this->ctx['account']['name'],
            'amount' => (string) $this->amount,
            'contact_id' => $this->ctx['contact_id'],
            'contact_name' => $this->ctx['contact_name'],
            'doc_type' => $this->ctx['doc_type'],
            'payment_id' => $this->id,
            'payment_no' => $this->payment_number,
            'transaction_date' => $this->payment_date?->toIso8601String(),
        ];
    }
}
