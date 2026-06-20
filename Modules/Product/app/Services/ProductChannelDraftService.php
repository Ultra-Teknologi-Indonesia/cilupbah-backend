<?php

namespace Modules\Product\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelListingValidator;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelDraft;
use Modules\Product\Models\ProductSyncLog;

class ProductChannelDraftService
{
    public function __construct(private ChannelListingValidator $validator) {}

    public function upsertDraft(string $productId, string $shopId, array $data, ?string $userId = null): ProductChannelDraft
    {
        $channelShopId = $this->requireChannelShopId($shopId);

        return ProductChannelDraft::updateOrCreate(
            [
                'product_id' => $productId,
                'channel_shop_id' => $channelShopId,
            ],
            array_filter([
                'channel_category_id' => $data['channel_category_id'] ?? null,
                'attribute_mapping' => $data['attribute_mapping'] ?? null,
                'price_override' => $data['price_override'] ?? null,
                'status' => $data['status'] ?? ProductChannelDraft::STATUS_DRAFT,
                'created_by' => $userId,
            ], fn ($value) => $value !== null)
        );
    }

    public function updateDraft(ProductChannelDraft $draft, array $data): ProductChannelDraft
    {
        $draft->update($data);

        return $draft->fresh('channelShop');
    }

    public function deleteDraft(ProductChannelDraft $draft): void
    {
        $draft->delete();
    }

    public function uploadDraft(string $draftId): ProductSyncLog
    {
        return DB::transaction(function () use ($draftId) {
            $draft = ProductChannelDraft::findOrFail($draftId);

            if ($draft->status === ProductChannelDraft::STATUS_CANCELLED) {
                throw new DomainException('Draft yang dibatalkan tidak dapat di-upload.');
            }

            $this->assertReadyForUpload($draft);

            $log = ProductSyncLog::record([
                'product_id' => $draft->product_id,
                'channel_shop_id' => $draft->channel_shop_id,
                'action' => ProductSyncLog::ACTION_UPLOAD,
                'status' => ProductSyncLog::STATUS_PENDING,
            ]);

            $attributeMapping = $draft->attribute_mapping;

            SyncProductToChannelJob::dispatch($draft->product_id, $draft->channel_shop_id, 'push', $attributeMapping)->afterCommit();

            $draft->delete();

            return $log;
        });
    }

    public function bulkUpload(array $draftIds): array
    {
        $uploaded = 0;
        $skipped = [];

        foreach ($draftIds as $id) {
            try {
                $this->uploadDraft($id);
                $uploaded++;
            } catch (\Throwable $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        return ['uploaded' => $uploaded, 'skipped' => $skipped];
    }

    private function assertReadyForUpload(ProductChannelDraft $draft): void
    {
        $product = Product::find($draft->product_id);
        if (! $product) {
            throw new DomainException('Produk tidak ditemukan.');
        }

        if ($this->validator->lacksVariationAttributes($product)) {
            $channelCode = ChannelShop::with('channel')->find($draft->channel_shop_id)?->channel?->code ?? 'channel';
            throw new DomainException(
                "Produk multi-varian tanpa atribut variasi — wajib diisi sebelum upload ke {$channelCode}."
            );
        }
    }

    protected function requireChannelShopId(string $shopId): string
    {
        $channelShopId = ChannelShop::where('shop_id', $shopId)->value('id');

        if (!$channelShopId) {
            throw new \RuntimeException('Toko tidak ditemukan atau tidak aktif', 422);
        }

        return $channelShopId;
    }
}
