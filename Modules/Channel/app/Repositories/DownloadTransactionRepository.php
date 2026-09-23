<?php

namespace Modules\Channel\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Channel\Models\DownloadTransaction;
use Modules\Channel\Models\DownloadTransactionProduct;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductSyncLog;
use Ramsey\Uuid\Uuid;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class DownloadTransactionRepository
{
    private const RELATIONS = [
        'channelShop.channel',
        'executor:id,email',
    ];

    public function paginate(): LengthAwarePaginator
    {
        return QueryBuilder::for(DownloadTransaction::class)
            ->with(self::RELATIONS)
            ->allowedFilters(
                AllowedFilter::exact('state'),
                AllowedFilter::callback('channel', fn ($query, $value) => $query->whereHas('channelShop.channel', fn ($channel) => $channel->where('code', $value))),
                AllowedFilter::callback('shop_id', fn ($query, $value) => $query->whereHas('channelShop', fn ($shop) => $shop->where('shop_id', $value))),
                AllowedFilter::callback('date_from', fn ($query, $value) => $query->whereDate('created_at', '>=', $value)),
                AllowedFilter::callback('date_to', fn ($query, $value) => $query->whereDate('created_at', '<=', $value)),
            )
            ->allowedSorts('created_at', 'trx_no')
            ->defaultSort('-created_at')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function find(string $id): DownloadTransaction
    {
        return DownloadTransaction::query()
            ->with(self::RELATIONS)
            ->findOrFail($id);
    }

    public function failureLogs(DownloadTransaction $transaction): Collection
    {
        return ProductSyncLog::query()
            ->where('channel_shop_id', $transaction->channel_shop_id)
            ->where('action', ProductSyncLog::ACTION_DOWNLOAD)
            ->where('status', ProductSyncLog::STATUS_FAILED)
            ->where('created_at', '>=', $transaction->created_at)
            ->where('created_at', '<=', $transaction->updated_at)
            ->orderByDesc('created_at')
            ->get(['payload', 'error_message', 'created_at']);
    }

    public function paginateTransactionProducts(DownloadTransaction $transaction): LengthAwarePaginator
    {
        return QueryBuilder::for(Product::class)
            ->select('products.*')
            ->distinct()
            ->join(
                'download_transaction_products',
                'download_transaction_products.product_id',
                '=',
                'products.id',
            )
            ->where('download_transaction_products.download_transaction_id', $transaction->id)
            ->whereIn('products.status', [
                Product::STATUS_MASTER,
                Product::STATUS_DOWNLOAD,
            ])
            ->with([
                'media',
                'channelMappings' => fn ($query) => $query->where('channel_shop_id', $transaction->channel_shop_id),
            ])
            ->allowedFilters(
                AllowedFilter::callback('is_master', function ($query, $value) {
                    filter_var($value, FILTER_VALIDATE_BOOLEAN)
                        ? $query->where('products.status', Product::STATUS_MASTER)
                        : $query->where('products.status', '!=', Product::STATUS_MASTER);
                }),
            )
            ->allowedSearch('name', 'sku')
            ->defaultSort('-products.updated_at')
            ->allowedSorts(
                AllowedSort::field('name', 'products.name'),
                AllowedSort::field('updated_at', 'products.updated_at'),
            )
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function recordDownloadedProducts(DownloadTransaction $transaction, array $externalProductIds): void
    {
        $externalProductIds = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            $externalProductIds,
        ))));

        if ($externalProductIds === []) {
            return;
        }

        $mappings = ProductChannelMapping::query()
            ->where('channel_shop_id', $transaction->channel_shop_id)
            ->whereIn('external_product_id', $externalProductIds)
            ->get(['product_id', 'external_product_id']);

        if ($mappings->isEmpty()) {
            return;
        }

        $now = now();
        $rows = $mappings->map(static fn (ProductChannelMapping $mapping): array => [
            'id' => Uuid::uuid7()->toString(),
            'download_transaction_id' => $transaction->id,
            'product_id' => $mapping->product_id,
            'external_product_id' => $mapping->external_product_id,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        DownloadTransactionProduct::query()->upsert(
            $rows,
            ['download_transaction_id', 'product_id', 'external_product_id'],
            ['updated_at'],
        );
    }

    public function recordDownloadedProductsUpdatedSince(DownloadTransaction $transaction): void
    {
        $externalProductIds = ProductChannelMapping::query()
            ->where('channel_shop_id', $transaction->channel_shop_id)
            ->where('updated_at', '>=', $transaction->created_at)
            ->pluck('external_product_id')
            ->all();

        $this->recordDownloadedProducts($transaction, $externalProductIds);
    }
}
