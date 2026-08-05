<?php

namespace Modules\Channel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Channel\Models\DownloadTransaction;
use Modules\Channel\Support\UploadErrorPresenter;

class DownloadTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $shop = $this->relationLoaded('channelShop') ? $this->channelShop : null;
        $channel = ($shop && $shop->relationLoaded('channel')) ? $shop->channel : null;
        $executor = $this->relationLoaded('executor') ? $this->executor : null;

        return [
            'trx_id' => $this->id,
            'trx_no' => $this->trx_no,
            'executed_by' => $executor->email ?? 'system',
            'store_name' => $shop->shop_name ?? null,
            'store_id' => $this->channel_shop_id,
            'channel_id' => $shop->channel_id ?? null,
            'channel_code' => $channel->code ?? null,
            'channel_name' => $channel->name ?? null,
            'created_date' => $this->created_at,
            'state' => $this->state,
            'is_downloaded' => $this->state === DownloadTransaction::STATE_DONE,
            'on_download_process' => $this->state === DownloadTransaction::STATE_DOWNLOADING,
            'total_downloaded' => $this->total_downloaded,
            'all_product' => $this->all_product,
            'progress_percent' => $this->progress_percent,
            'error_message' => $this->humanReadableError($channel->code ?? ''),
            'error' => $this->errorDetail($channel->code ?? ''),
        ];
    }

    protected function humanReadableError(string $channelCode): ?string
    {
        if ($this->state !== DownloadTransaction::STATE_FAILED || empty($this->error_message)) {
            return $this->error_message;
        }

        return UploadErrorPresenter::fromMessage($channelCode, (string) $this->error_message)['reason'];
    }

    protected function errorDetail(string $channelCode): ?array
    {
        if ($this->state !== DownloadTransaction::STATE_FAILED || empty($this->error_message)) {
            return null;
        }

        return UploadErrorPresenter::fromMessage($channelCode, (string) $this->error_message);
    }
}
