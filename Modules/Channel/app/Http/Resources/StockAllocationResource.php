<?php

namespace Modules\Channel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockAllocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $channelName = strtoupper((string) optional($this->channel)->name);
        $isTotal = $this->stock_source_mode === 'total';

        return [
            'store_id' => $this->id,
            'channel_code' => strtolower((string) optional($this->channel)->code),
            'channel_name' => $channelName,
            'store_name' => $this->shop_name,
            'full_store_name' => trim($channelName . ' - ' . $this->shop_name, ' -'),
            'stock_source_mode' => $this->stock_source_mode ?? 'location',
            'location_id' => $isTotal ? null : $this->stock_source_location_id,
            'location_name' => $isTotal ? null : optional($this->stockSourceLocation)->location_name,
            'location_code' => $isTotal ? null : optional($this->stockSourceLocation)->location_code,
        ];
    }
}
