<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\ChannelShop;
use Modules\Inventory\Models\PriceAdjustment;
use Modules\Inventory\Models\PriceAdjustmentItem;
use Modules\Inventory\Models\VariantLocationPrice;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;

class PriceAdjustmentService
{
    public function list(array $params = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $perPage = min((int) ($params['per_page'] ?? 10), 100);
        $search = $params['search'] ?? null;
        $status = $params['status'] ?? null;
        $type = $params['type'] ?? null;

        $query = PriceAdjustment::query()
            ->withCount('items')
            ->orderByDesc('created_at');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('adjustment_no', 'ilike', "%{$search}%")
                    ->orWhere('notes', 'ilike', "%{$search}%");
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($type) {
            $query->where('type', $type);
        }

        return $query->paginate($perPage);
    }

    public function show(string $id): ?PriceAdjustment
    {
        return PriceAdjustment::with([
            'items.variant' => fn ($q) => $q->select('id', 'product_id', 'sku', 'sell_price', 'buy_price')
                ->with(['product:id,name,thumbnail', 'options:id,variant_id,option_value']),
            'items.channelShop' => fn ($q) => $q->select('id', 'channel_id', 'shop_name')
                ->with('channel:id,name,code'),
            'items.location:id,location_name,is_pos',
        ])->find($id);
    }

    public function store(array $data, string $createdBy): PriceAdjustment
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $adjustment = PriceAdjustment::create([
                'adjustment_no' => $this->generateNumber(),
                'adjustment_date' => $data['adjustment_date'],
                'type' => $data['type'] ?? PriceAdjustment::TYPE_ONLINE,
                'status' => PriceAdjustment::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
                'created_by' => $createdBy,
            ]);

            foreach ($data['items'] as $item) {
                $variant = ProductVariant::find($item['variant_id']);
                if (! $variant) {
                    continue;
                }

                $oldPrice = $this->resolveOldPrice(
                    $variant,
                    $data['type'] ?? 'online',
                    $item['channel_shop_id'] ?? null,
                    $item['location_id'] ?? null
                );

                PriceAdjustmentItem::create([
                    'price_adjustment_id' => $adjustment->id,
                    'variant_id' => $item['variant_id'],
                    'channel_shop_id' => $item['channel_shop_id'] ?? null,
                    'location_id' => $item['location_id'] ?? null,
                    'old_price' => $oldPrice,
                    'new_price' => $item['new_price'],
                ]);
            }

            return $adjustment->load('items');
        });
    }

    public function apply(string $id, string $appliedBy): PriceAdjustment
    {
        return DB::transaction(function () use ($id, $appliedBy) {
            $adjustment = PriceAdjustment::with('items')->findOrFail($id);

            if ($adjustment->status !== PriceAdjustment::STATUS_DRAFT) {
                throw new \RuntimeException('Hanya penyesuaian berstatus draft yang bisa diterapkan.');
            }

            foreach ($adjustment->items as $item) {
                if ($adjustment->type === PriceAdjustment::TYPE_ONLINE) {
                    $this->applyOnlinePrice($item);
                } else {
                    $this->applyOfflinePrice($item);
                }
            }

            $adjustment->update([
                'status' => PriceAdjustment::STATUS_APPLIED,
                'applied_by' => $appliedBy,
                'applied_at' => now(),
            ]);

            return $adjustment->fresh();
        });
    }

    public function cancel(string $id): PriceAdjustment
    {
        $adjustment = PriceAdjustment::findOrFail($id);

        if ($adjustment->status !== PriceAdjustment::STATUS_DRAFT) {
            throw new \RuntimeException('Hanya penyesuaian berstatus draft yang bisa dibatalkan.');
        }

        $adjustment->update(['status' => PriceAdjustment::STATUS_CANCELLED]);

        return $adjustment;
    }

    public function destroy(string $id): void
    {
        $adjustment = PriceAdjustment::findOrFail($id);

        if ($adjustment->status === PriceAdjustment::STATUS_APPLIED) {
            throw new \RuntimeException('Penyesuaian yang sudah diterapkan tidak bisa dihapus.');
        }

        $adjustment->delete();
    }

    private function applyOnlinePrice(PriceAdjustmentItem $item): void
    {
        if (! $item->channel_shop_id) {
            $item->variant->update(['sell_price' => $item->new_price]);
            return;
        }

        $variantMapping = ProductVariantChannelMapping::query()
            ->where('variant_id', $item->variant_id)
            ->whereHas('channelMapping', fn ($q) => $q->where('channel_shop_id', $item->channel_shop_id))
            ->first();

        if ($variantMapping) {
            $variantMapping->update(['override_price' => $item->new_price]);
        }
    }

    private function applyOfflinePrice(PriceAdjustmentItem $item): void
    {
        if (! $item->location_id) {
            $item->variant->update(['sell_price' => $item->new_price]);
            return;
        }

        VariantLocationPrice::updateOrCreate(
            [
                'variant_id' => $item->variant_id,
                'location_id' => $item->location_id,
            ],
            [
                'price' => $item->new_price,
                'is_active' => true,
            ]
        );
    }

    private function resolveOldPrice(ProductVariant $variant, string $type, ?string $channelShopId, ?string $locationId): float
    {
        if ($type === PriceAdjustment::TYPE_ONLINE && $channelShopId) {
            $mapping = ProductVariantChannelMapping::query()
                ->where('variant_id', $variant->id)
                ->whereHas('channelMapping', fn ($q) => $q->where('channel_shop_id', $channelShopId))
                ->first();

            return (float) ($mapping?->override_price ?? $variant->sell_price);
        }

        if ($type === PriceAdjustment::TYPE_OFFLINE && $locationId) {
            $locPrice = VariantLocationPrice::where('variant_id', $variant->id)
                ->where('location_id', $locationId)
                ->first();

            return (float) ($locPrice?->price ?? $variant->sell_price);
        }

        return (float) $variant->sell_price;
    }

    private function generateNumber(): string
    {
        $prefix = 'PA-' . now()->format('ymd');
        $last = PriceAdjustment::where('adjustment_no', 'like', $prefix . '%')
            ->orderByDesc('adjustment_no')
            ->value('adjustment_no');

        $seq = 1;
        if ($last) {
            $seq = ((int) substr($last, -4)) + 1;
        }

        return $prefix . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
