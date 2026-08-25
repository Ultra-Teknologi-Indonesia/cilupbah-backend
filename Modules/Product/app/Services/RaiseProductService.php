<?php

namespace Modules\Product\Services;

use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Channel\Services\ShopeeProductService;
use Modules\Product\Jobs\RaiseProductJob;
use Modules\Product\Models\RaiseProduct;
use Modules\Product\Models\RaiseProductDetail;
use Modules\Product\Repositories\RaiseProductRepository;

class RaiseProductService
{
    public function __construct(
        private RaiseProductRepository $repository,
        private ShopeeProductService $shopee,
    ) {}

    public function paginate(): LengthAwarePaginator
    {
        return $this->repository->paginate();
    }

    public function find(string $id): RaiseProduct
    {
        return $this->repository->find($id);
    }

    public function paginateDetails(string $id): LengthAwarePaginator
    {
        return $this->repository->paginateDetails($id);
    }

    public function create(string $shopId, ?string $userId): RaiseProduct
    {
        $channelShopId = $this->repository->resolveChannelShopId($shopId);

        if (! $channelShopId) {
            throw new DomainException('Toko tidak ditemukan atau tidak aktif');
        }

        if ($this->repository->existsForShop($channelShopId)) {
            throw new DomainException('Toko sudah memiliki data naikkan');
        }

        $header = $this->repository->createHeader([
            'channel_shop_id' => $channelShopId,
            'created_by' => $userId,
        ]);

        return $this->repository->find($header->id);
    }

    public function addProduct(string $raiseProductId, string $mappingId): RaiseProductDetail
    {
        $raiseProduct = $this->repository->find($raiseProductId);

        $mapping = $this->repository->findMapping($mappingId);

        if (! $mapping) {
            throw new DomainException('Produk channel tidak ditemukan');
        }

        if ($mapping->channel_shop_id !== $raiseProduct->channel_shop_id) {
            throw new DomainException('Produk bukan milik toko ini');
        }

        if ($this->repository->mappingIsRaised($mappingId)) {
            throw new DomainException('Produk sudah dinaikkan di data naikkan lain');
        }

        $detail = $this->repository->createDetail([
            'raise_product_id' => $raiseProductId,
            'product_channel_mapping_id' => $mappingId,
        ]);

        return $this->repository->findDetailFull($detail->id);
    }

    public function removeProduct(string $raiseProductId, string $detailId): void
    {
        $detail = $this->repository->findDetail($raiseProductId, $detailId);

        if (! $detail) {
            throw new ModelNotFoundException();
        }

        $this->repository->deleteDetail($detail);
    }

    public function updateProduct(string $raiseProductId, string $detailId, array $data): RaiseProductDetail
    {
        $detail = $this->repository->findDetail($raiseProductId, $detailId);

        if (! $detail) {
            throw new ModelNotFoundException();
        }

        $this->repository->updateDetail($detail, $data);

        return $this->repository->findDetailFull($detailId);
    }

    public function delete(string $id): void
    {
        $raiseProduct = $this->repository->find($id);

        $this->repository->deleteHeader($raiseProduct);
    }

    public function raise(string $raiseProductId, ?array $detailIds = null): RaiseProduct
    {
        $this->repository->find($raiseProductId);

        $this->repository->markRaiseStart($raiseProductId, $detailIds);

        RaiseProductJob::dispatch($raiseProductId, $detailIds)->afterCommit();

        return $this->repository->find($raiseProductId);
    }

    public function paginateHistory(string $id): LengthAwarePaginator
    {
        return $this->repository->paginateHistory($id);
    }

    public function executeRaise(string $raiseProductId, ?array $detailIds = null): void
    {
        $details = $this->repository->detailsToRaise($raiseProductId, $detailIds);

        if ($details->isEmpty()) {
            return;
        }

        $channel = $details->first()->channelMapping?->channelShop?->channel;

        if (! $channel || $channel->code !== 'shopee') {
            foreach ($details as $detail) {
                $this->repository->markRaiseResult($detail, false, 'Channel belum didukung untuk naikkan produk');
            }

            return;
        }

        $shopId = $details->first()->channelMapping->channelShop->shop_id;
        $extIdToDetails = $details->groupBy(fn ($d) => $d->channelMapping->external_product_id);
        $extIds = $extIdToDetails->keys()->all();

        try {
            $results = $this->shopee->boostItem($shopId, $extIds);
        } catch (\Exception $e) {
            foreach ($details as $detail) {
                $this->repository->markRaiseResult($detail, false, 'Gagal menghubungi Shopee: '.$e->getMessage());
            }

            return;
        }

        foreach ($extIdToDetails as $extId => $group) {
            $result = $results[(string) $extId] ?? ['success' => false, 'reason' => 'Tidak ada respons'];
            foreach ($group as $detail) {
                $this->repository->markRaiseResult(
                    $detail,
                    $result['success'],
                    $result['success'] ? 'Produk berhasil dinaikkan di Shopee' : ($result['reason'] ?? 'Gagal tanpa alasan'),
                    $result['success'] ? now()->addHours(4) : now()
                );
            }
        }
    }
}
