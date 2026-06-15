<?php

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bentuk ringkas Account untuk dropdown master data (form Buat Produk).
 */
class AccountLookupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'account_id' => $this->id,
            'account_code' => $this->account_code,
            'account_name' => $this->display_name,
            'account_type' => $this->account_type,
        ];
    }
}
