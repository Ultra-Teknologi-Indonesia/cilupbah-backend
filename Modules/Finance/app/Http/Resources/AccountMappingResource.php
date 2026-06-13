<?php

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountMappingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $account = $this->resource['account'] ?? null;

        return [
            'key' => $this->resource['key'],
            'label' => $this->resource['label'],
            'configured' => $this->resource['configured'],
            'account' => $account ? [
                'id' => $account->id,
                'code' => $account->account_code,
                'name' => $account->account_name,
            ] : null,
        ];
    }
}
