<?php

namespace Modules\Inventory\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\ChannelShop;
use Modules\Inventory\Models\VariantLocationPrice;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;

class PriceService
{
    public function getOnlinePrices(array $params = []): array
    {
        $perPage = min((int) ($params['per_page'] ?? 10), 100);
        $page = (int) ($params['page'] ?? 1);
        $search = $params['search'] ?? null;
        $channelShopId = $params['channel_shop_id'] ?? null;
        $sort = $params['sort'] ?? 'sku';
        $direction = $params['direction'] ?? 'asc';

        $query = ProductVariant::query()
            ->select('product_variants.*')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereNull('products.deleted_at')
            ->with([
                'product:id,name,thumbnail',
                'options:id,variant_id,option_name,option_value',
                'channelMappings' => function ($q) {
                    $q->with(['channelMapping' => function ($q2) {
                        $q2->select('id', 'channel_shop_id', 'sync_status')
                            ->where('sync_status', 'synced')
                            ->with('channelShop:id,channel_id,shop_name');
                    }]);
                },
            ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('product_variants.sku', 'ilike', "%{$search}%")
                    ->orWhere('products.name', 'ilike', "%{$search}%");
            });
        }

        if ($channelShopId) {
            $query->whereHas('channelMappings.channelMapping', function ($q) use ($channelShopId) {
                $q->where('channel_shop_id', $channelShopId)
                    ->where('sync_status', 'synced');
            });
        }

        $allowedSorts = ['sku', 'sell_price', 'buy_price', 'products.name'];
        $sortCol = in_array($sort, $allowedSorts) ? $sort : 'sku';
        $query->orderBy($sortCol, $direction === 'desc' ? 'desc' : 'asc');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $channels = ChannelShop::query()
            ->where('is_active', true)
            ->whereNotNull('shop_name')
            ->with('channel:id,name,code')
            ->get()
            ->map(fn (ChannelShop $shop) => [
                'channel_shop_id' => $shop->id,
                'channel_name' => $shop->channel?->name ?? '',
                'channel_code' => $shop->channel?->code ?? '',
                'store_name' => $shop->shop_name,
            ])
            ->values();

        $data = collect($paginator->items())->map(function (ProductVariant $variant) {
            $prices = $variant->channelMappings
                ->filter(fn ($vm) => $vm->channelMapping && $vm->channelMapping->sync_status === 'synced')
                ->map(fn ($vm) => [
                    'channel_shop_id' => $vm->channelMapping->channel_shop_id,
                    'store_name' => $vm->channelMapping->channelShop?->shop_name ?? '',
                    'channel_code' => $vm->channelMapping->channelShop?->channel?->code ?? '',
                    'price' => (float) ($vm->override_price ?? $variant->sell_price),
                    'sell_here' => true,
                ])
                ->values();

            return [
                'variant_id' => $variant->id,
                'product_id' => $variant->product_id,
                'product_name' => $variant->product?->name ?? '',
                'sku' => $variant->sku,
                'thumbnail' => $variant->product?->thumbnail,
                'variation_values' => $variant->options->pluck('option_value')->toArray(),
                'sell_price' => (float) $variant->sell_price,
                'buy_price' => (float) $variant->buy_price,
                'prices' => $prices,
            ];
        });

        return [
            'channels' => $channels,
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function getOfflinePrices(array $params = []): array
    {
        $perPage = min((int) ($params['per_page'] ?? 10), 100);
        $page = (int) ($params['page'] ?? 1);
        $search = $params['search'] ?? null;
        $locationId = $params['location_id'] ?? null;
        $sort = $params['sort'] ?? 'sku';
        $direction = $params['direction'] ?? 'asc';

        $query = ProductVariant::query()
            ->select('product_variants.*')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereNull('products.deleted_at')
            ->with([
                'product:id,name,thumbnail',
                'options:id,variant_id,option_name,option_value',
                'locationPrices' => function ($q) use ($locationId) {
                    if ($locationId) {
                        $q->where('location_id', $locationId);
                    }
                    $q->with('location:id,location_name,is_pos');
                },
            ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('product_variants.sku', 'ilike', "%{$search}%")
                    ->orWhere('products.name', 'ilike', "%{$search}%");
            });
        }

        if ($locationId) {
            $query->whereHas('locationPrices', fn ($q) => $q->where('location_id', $locationId));
        }

        $allowedSorts = ['sku', 'sell_price', 'buy_price', 'products.name'];
        $sortCol = in_array($sort, $allowedSorts) ? $sort : 'sku';
        $query->orderBy($sortCol, $direction === 'desc' ? 'desc' : 'asc');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $locations = Location::query()
            ->where('is_active', true)
            ->whereNot('location_code', Location::SYSTEM_TRANSIT_CODE)
            ->select('id', 'location_name', 'is_pos')
            ->orderBy('location_name')
            ->get()
            ->map(fn (Location $loc) => [
                'location_id' => $loc->id,
                'location_name' => $loc->location_name,
                'is_pos_outlet' => (bool) $loc->is_pos,
            ]);

        $data = collect($paginator->items())->map(function (ProductVariant $variant) {
            $priceByLocation = $variant->locationPrices->map(fn ($lp) => [
                'location_id' => $lp->location_id,
                'location_name' => $lp->location?->location_name ?? '',
                'price' => (float) $lp->price,
                'is_active' => (bool) $lp->is_active,
            ])->values();

            return [
                'variant_id' => $variant->id,
                'product_id' => $variant->product_id,
                'product_name' => $variant->product?->name ?? '',
                'sku' => $variant->sku,
                'thumbnail' => $variant->product?->thumbnail,
                'variation_values' => $variant->options->pluck('option_value')->toArray(),
                'sell_price' => (float) $variant->sell_price,
                'buy_price' => (float) $variant->buy_price,
                'price_by_location' => $priceByLocation,
            ];
        });

        return [
            'locations' => $locations,
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function updateOnlinePrices(array $items): int
    {
        $updated = 0;

        DB::transaction(function () use ($items, &$updated) {
            foreach ($items as $item) {
                $variantMapping = \Modules\Product\Models\ProductVariantChannelMapping::query()
                    ->where('variant_id', $item['variant_id'])
                    ->whereHas('channelMapping', fn ($q) => $q->where('channel_shop_id', $item['channel_shop_id']))
                    ->first();

                if ($variantMapping) {
                    $variantMapping->update(['override_price' => $item['price']]);
                    $updated++;
                }
            }
        });

        return $updated;
    }

    public function updateOfflinePrices(array $items): int
    {
        $updated = 0;

        DB::transaction(function () use ($items, &$updated) {
            foreach ($items as $item) {
                VariantLocationPrice::updateOrCreate(
                    [
                        'variant_id' => $item['variant_id'],
                        'location_id' => $item['location_id'],
                    ],
                    [
                        'price' => $item['price'],
                        'is_active' => $item['is_active'] ?? true,
                    ]
                );
                $updated++;
            }
        });

        return $updated;
    }
}
