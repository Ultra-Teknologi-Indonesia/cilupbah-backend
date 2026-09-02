<?php

namespace Modules\Sales\Services;

use App\Exceptions\UserFacingException;
use App\Support\ChannelWarehousePolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\OrderBinAllocation;
use Modules\Sales\Models\OrderBuyerConfirmation;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Repositories\OrderDirectCompletionRepository;

class OrderDirectCompletionService
{
    public const BLOCK_NOT_ELIGIBLE = 'not_eligible';

    public const BLOCK_HAS_PICKLIST = 'has_picklist';

    public const BLOCK_SHADOW = 'shadow';

    public const BLOCK_CANCEL_PENDING = 'cancel_pending';

    public const BLOCK_AWAITING_BUYER = 'awaiting_buyer_confirmation';

    public const BLOCK_STOCK_SHORT = 'stock_short';

    public const BLOCK_FAILED = 'failed';

    public const BLOCK_INVALID_LOCATION = 'invalid_location';

    private const ELIGIBLE_STATUSES = ['reserved'];

    public function __construct(
        protected OrderDirectCompletionRepository $repository,
        protected SalesOrderService $orderService,
        protected StockService $stockService,
        protected ChannelWarehousePolicy $channelWarehousePolicy,
    ) {}

    public function preview(array $orderIds): array
    {
        $locationId = $this->requireSourceLocation();
        $orders = $this->repository->ordersForCompletion($orderIds);
        $eligible = $this->partitionEligible($orders, $orderIds, $blocked);

        $needsByOrder = [];
        foreach ($eligible as $order) {
            $needsByOrder[$order->id] = $this->componentNeeds($order);
        }

        $itemIds = $this->itemIdsFromNeeds($needsByOrder);
        $binStocks = $this->repository->binStocks($itemIds, $locationId);
        $meta = $this->repository->variantMeta($itemIds);

        $plan = $this->allocate($eligible, $needsByOrder, $this->queuesFromBinStocks($binStocks));

        foreach ($plan['short'] as $orderId => $shortage) {
            $order = $eligible->firstWhere('id', $orderId);
            $blocked[] = $this->blockedEntry($order, self::BLOCK_STOCK_SHORT, $shortage);
        }

        $totalRequired = $this->sumNeeds($needsByOrder);
        $completableRequired = $this->sumNeeds(array_diff_key($needsByOrder, $plan['short']));

        $items = [];
        foreach ($this->itemIdsFromNeeds($needsByOrder) as $itemId) {
            $bins = $binStocks[$itemId] ?? [];
            $available = array_sum(array_column($bins, 'on_hand'));
            $completable = $completableRequired[$itemId] ?? 0;

            $items[] = [
                'item_id' => $itemId,
                'sku' => $meta[$itemId]['sku'] ?? '',
                'name' => $meta[$itemId]['name'] ?? '',
                'qty_required' => $totalRequired[$itemId] ?? 0,
                'qty_completable' => $completable,
                'qty_available' => $available,
                'shortage' => max(0, ($totalRequired[$itemId] ?? 0) - $available),
                'bins' => $bins,
                'suggested' => $this->suggestBins($bins, $completable),
            ];
        }

        return [
            'location_id' => $locationId,
            'completable_order_ids' => array_values(array_diff(
                $eligible->pluck('id')->all(),
                array_keys($plan['short']),
            )),
            'items' => $items,
            'blocked' => $blocked,
        ];
    }

    public function complete(array $orderIds, array $allocationPlan): array
    {
        $locationId = $this->requireSourceLocation();
        $orders = $this->repository->ordersForCompletion($orderIds);
        $eligible = $this->partitionEligible($orders, $orderIds, $blocked);

        $needsByOrder = [];
        foreach ($eligible as $order) {
            $needsByOrder[$order->id] = $this->componentNeeds($order);
        }

        $queues = $this->queuesFromPlan($allocationPlan, $locationId);

        if ($queues === []) {
            $itemIds = $this->itemIdsFromNeeds($needsByOrder);
            $queues = $this->queuesFromBinStocks($this->repository->binStocks($itemIds, $locationId));
        }

        $plan = $this->allocate($eligible, $needsByOrder, $queues);

        $completed = [];
        $raised = 0;

        foreach ($plan['short'] as $orderId => $shortage) {
            $order = $eligible->firstWhere('id', $orderId);
            $raised += $this->raiseBuyerConfirmation($order, $shortage);
            $blocked[] = $this->blockedEntry($order, self::BLOCK_STOCK_SHORT, $shortage);
        }

        foreach ($plan['draws'] as $orderId => $draws) {
            $order = $eligible->firstWhere('id', $orderId);

            try {
                $this->commitOrder($order, $locationId, $draws);
                $completed[] = [
                    'order_id' => $order->id,
                    'salesorder_no' => $order->salesorder_no,
                ];
            } catch (\Throwable $e) {
                $blocked[] = [
                    'order_id' => $order->id,
                    'salesorder_no' => $order->salesorder_no,
                    'reason' => self::BLOCK_FAILED,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'completed' => $completed,
            'completed_count' => count($completed),
            'blocked' => $blocked,
            'raised_confirmations' => $raised,
        ];
    }

    private function commitOrder(SalesOrder $order, string $locationId, array $draws): void
    {
        DB::transaction(function () use ($order, $locationId, $draws) {
            $locked = $this->repository->lockOrder($order->id);

            if (! $locked || ! $this->isEligibleStatus($locked)) {
                throw new UserFacingException('Pesanan sudah berubah status, muat ulang halaman.');
            }

            if ($this->repository->orderIdsWithPicklist([$locked->id]) !== []) {
                throw new UserFacingException('Pesanan sudah masuk daftar picking, selesaikan dari sana.');
            }

            $this->channelWarehousePolicy->assertOrderAndTargetLocation(
                $locked->source,
                $locked->location_id,
                $locationId,
                'Penyelesaian langsung',
            );

            $actorId = Auth::id() ?: null;
            $now = now();

            foreach ($draws as $draw) {
                $this->stockService->consumeFromBin(
                    $draw['sku'],
                    $draw['item_id'],
                    $locationId,
                    $draw['bin_id'],
                    $draw['qty'],
                    $locked->salesorder_no,
                    'ORDER_COMPLETE_OUT',
                    $actorId ? (string) $actorId : null,
                );

                OrderBinAllocation::create([
                    'order_id' => $locked->id,
                    'order_item_id' => $draw['order_item_id'],
                    'item_id' => $draw['item_id'],
                    'location_id' => $locationId,
                    'bin_id' => $draw['bin_id'],
                    'qty' => $draw['qty'],
                    'completed_by' => $actorId,
                    'completed_at' => $now,
                ]);
            }

            $this->orderService->markAsComplete([$locked->id]);
        });
    }

    private function allocate(Collection $orders, array $needsByOrder, array $queues): array
    {
        $remaining = [];
        foreach ($queues as $itemId => $entries) {
            foreach ($entries as $entry) {
                $remaining[$itemId][] = [
                    'bin_id' => $entry['bin_id'],
                    'qty' => $entry['qty'],
                ];
            }
        }

        $draws = [];
        $short = [];

        foreach ($this->sortByAge($orders) as $order) {
            $needs = $needsByOrder[$order->id] ?? [];
            $orderDraws = [];
            $shortage = [];
            $consumed = [];

            foreach ($needs as $need) {
                $outstanding = $need['qty'];
                $pool = $remaining[$need['item_id']] ?? [];

                foreach ($pool as $index => $slot) {
                    if ($outstanding <= 0) {
                        break;
                    }

                    $taken = min($outstanding, $slot['qty'] - ($consumed[$need['item_id']][$index] ?? 0));

                    if ($taken <= 0) {
                        continue;
                    }

                    $consumed[$need['item_id']][$index] = ($consumed[$need['item_id']][$index] ?? 0) + $taken;
                    $outstanding -= $taken;

                    $orderDraws[] = [
                        'order_item_id' => $need['order_item_id'],
                        'item_id' => $need['item_id'],
                        'sku' => $need['sku'],
                        'bin_id' => $slot['bin_id'],
                        'qty' => $taken,
                    ];
                }

                if ($outstanding > 0) {
                    $shortage[] = [
                        'item_id' => $need['item_id'],
                        'order_item_id' => $need['order_item_id'],
                        'sku' => $need['sku'],
                        'qty_short' => $outstanding,
                    ];
                }
            }

            if ($shortage !== []) {
                $short[$order->id] = $shortage;

                continue;
            }

            foreach ($consumed as $itemId => $byIndex) {
                foreach ($byIndex as $index => $qty) {
                    $remaining[$itemId][$index]['qty'] -= $qty;
                }
            }

            $draws[$order->id] = $this->mergeDraws($orderDraws);
        }

        return ['draws' => $draws, 'short' => $short];
    }

    private function mergeDraws(array $draws): array
    {
        $merged = [];

        foreach ($draws as $draw) {
            $key = $draw['order_item_id'].'|'.$draw['item_id'].'|'.$draw['bin_id'];

            if (! isset($merged[$key])) {
                $merged[$key] = $draw;

                continue;
            }

            $merged[$key]['qty'] += $draw['qty'];
        }

        return array_values($merged);
    }

    private function componentNeeds(SalesOrder $order): array
    {
        $itemIds = $order->items->pluck('item_id')->filter()->unique()->values()->all();
        $bundles = $this->repository->bundleComponents($itemIds);
        $meta = $this->repository->variantMeta(array_values(array_unique(array_merge(
            $itemIds,
            collect($bundles)->flatten(1)->pluck('item_id')->all(),
        ))));

        $needs = [];

        foreach ($order->items as $item) {
            if (! $item->item_id) {
                continue;
            }

            $qty = (int) $item->qty_in_base;

            if ($qty <= 0) {
                continue;
            }

            $components = $bundles[$item->item_id] ?? null;

            if ($components === null) {
                $needs[] = [
                    'order_item_id' => $item->id,
                    'item_id' => $item->item_id,
                    'sku' => $item->sku ?: ($meta[$item->item_id]['sku'] ?? "item:{$item->item_id}"),
                    'qty' => $qty,
                ];

                continue;
            }

            foreach ($components as $component) {
                $needs[] = [
                    'order_item_id' => $item->id,
                    'item_id' => $component['item_id'],
                    'sku' => $meta[$component['item_id']]['sku'] ?? "item:{$component['item_id']}",
                    'qty' => $qty * $component['qty'],
                ];
            }
        }

        return $needs;
    }

    private function partitionEligible(Collection $orders, array $requestedIds, ?array &$blocked): Collection
    {
        $blocked = [];
        $picklistOrderIds = array_flip($this->repository->orderIdsWithPicklist($requestedIds));
        $awaiting = $this->awaitingBuyerOrderIds($requestedIds);

        $eligible = collect();

        foreach ($orders as $order) {
            $reason = $this->blockReasonFor($order, $picklistOrderIds, $awaiting);

            if ($reason !== null) {
                $blocked[] = $this->blockedEntry($order, $reason);

                continue;
            }

            $eligible->push($order);
        }

        return $eligible;
    }

    private function blockReasonFor(SalesOrder $order, array $picklistOrderIds, array $awaiting): ?string
    {
        if ($order->is_shadow) {
            return self::BLOCK_SHADOW;
        }

        if ($order->is_canceled || ! $this->isEligibleStatus($order)) {
            return self::BLOCK_NOT_ELIGIBLE;
        }

        if ($order->channel_cancel_status === 'pending') {
            return self::BLOCK_CANCEL_PENDING;
        }

        if ($this->channelWarehousePolicy->isChannelSource($order->source)
            && (string) $order->location_id !== $this->channelWarehousePolicy->officialSmallWarehouseId()
        ) {
            return self::BLOCK_INVALID_LOCATION;
        }

        if (isset($picklistOrderIds[$order->id])) {
            return self::BLOCK_HAS_PICKLIST;
        }

        if (isset($awaiting[$order->id])) {
            return self::BLOCK_AWAITING_BUYER;
        }

        return null;
    }

    private function isEligibleStatus(SalesOrder $order): bool
    {
        return in_array((string) $order->status, self::ELIGIBLE_STATUSES, true);
    }

    private function awaitingBuyerOrderIds(array $orderIds): array
    {
        return array_flip(
            OrderBuyerConfirmation::whereIn('order_id', $orderIds)
                ->awaitingDecision()
                ->distinct()
                ->pluck('order_id')
                ->all(),
        );
    }

    private function blockedEntry(?SalesOrder $order, string $reason, array $shortage = []): array
    {
        return [
            'order_id' => $order?->id,
            'salesorder_no' => $order?->salesorder_no,
            'reason' => $reason,
            'message' => $this->blockMessage($order, $reason),
            'shortage' => $shortage,
        ];
    }

    private function blockMessage(?SalesOrder $order, string $reason): string
    {
        return match ($reason) {
            self::BLOCK_HAS_PICKLIST => $this->picklistMessage($order),
            self::BLOCK_SHADOW => 'Pesanan bayangan tidak memotong stok.',
            self::BLOCK_CANCEL_PENDING => 'Permintaan pembatalan sedang diproses.',
            self::BLOCK_AWAITING_BUYER => 'Menunggu konfirmasi pembeli.',
            self::BLOCK_STOCK_SHORT => 'Stok Gudang Kecil tidak mencukupi.',
            self::BLOCK_INVALID_LOCATION => 'Lokasi pesanan channel bukan Gudang Kecil.',
            default => 'Pesanan belum siap diselesaikan.',
        };
    }

    private function picklistMessage(?SalesOrder $order): string
    {
        $picklistNo = $order ? $this->repository->picklistNumberForOrder($order->id) : null;

        return $picklistNo
            ? "Sudah masuk picking {$picklistNo}, selesaikan dari sana."
            : 'Sudah masuk daftar picking, selesaikan dari sana.';
    }

    private function raiseBuyerConfirmation(?SalesOrder $order, array $shortage): int
    {
        if (! $order) {
            return 0;
        }

        $raised = 0;
        $actorId = Auth::id() ?: null;

        foreach ($shortage as $row) {
            $exists = OrderBuyerConfirmation::where('order_id', $order->id)
                ->where('item_id', $row['item_id'])
                ->unresolved()
                ->exists();

            if ($exists) {
                continue;
            }

            OrderBuyerConfirmation::create([
                'order_id' => $order->id,
                'order_item_id' => $row['order_item_id'],
                'item_id' => $row['item_id'],
                'qty_short' => $row['qty_short'],
                'raised_by' => $actorId,
                'raised_at' => now(),
            ]);

            $raised++;
        }

        return $raised;
    }

    private function sumNeeds(array $needsByOrder): array
    {
        $required = [];

        foreach ($needsByOrder as $needs) {
            foreach ($needs as $need) {
                $required[$need['item_id']] = ($required[$need['item_id']] ?? 0) + $need['qty'];
            }
        }

        return $required;
    }

    private function itemIdsFromNeeds(array $needsByOrder): array
    {
        $ids = [];
        foreach ($needsByOrder as $needs) {
            foreach ($needs as $need) {
                $ids[$need['item_id']] = true;
            }
        }

        return array_keys($ids);
    }

    private function queuesFromBinStocks(array $binStocks): array
    {
        $queues = [];

        foreach ($binStocks as $itemId => $bins) {
            foreach ($bins as $bin) {
                $queues[$itemId][] = [
                    'bin_id' => $bin['bin_id'],
                    'qty' => (int) $bin['on_hand'],
                ];
            }
        }

        return $queues;
    }

    private function queuesFromPlan(array $allocationPlan, string $locationId): array
    {
        if ($allocationPlan === []) {
            return [];
        }

        $binIds = [];
        foreach ($allocationPlan as $line) {
            foreach ($line['bins'] ?? [] as $bin) {
                $binIds[] = $bin['bin_id'];
            }
        }

        $allowed = array_flip($this->repository->binsBelongingToLocation(
            array_values(array_unique($binIds)),
            $locationId,
        ));

        $queues = [];

        foreach ($allocationPlan as $line) {
            $itemId = $line['item_id'] ?? null;

            if (! $itemId) {
                continue;
            }

            foreach ($line['bins'] ?? [] as $bin) {
                $qty = (int) ($bin['qty'] ?? 0);

                if ($qty <= 0 || ! isset($allowed[$bin['bin_id']])) {
                    continue;
                }

                $queues[$itemId][] = [
                    'bin_id' => $bin['bin_id'],
                    'qty' => $qty,
                ];
            }
        }

        return $queues;
    }

    private function suggestBins(array $bins, int $qty): array
    {
        $suggested = [];
        $outstanding = $qty;

        foreach ($bins as $bin) {
            if ($outstanding <= 0) {
                break;
            }

            $take = min($outstanding, (int) $bin['on_hand']);

            if ($take <= 0) {
                continue;
            }

            $suggested[] = [
                'bin_id' => $bin['bin_id'],
                'bin_code' => $bin['bin_code'],
                'qty' => $take,
            ];

            $outstanding -= $take;
        }

        return $suggested;
    }

    private function sortByAge(Collection $orders): Collection
    {
        return $orders->sortBy(fn (SalesOrder $order) => (string) ($order->transaction_date ?? $order->created_at))->values();
    }

    private function requireSourceLocation(): string
    {
        $locationId = $this->repository->sourceLocationId();

        if (! $locationId) {
            throw new UserFacingException('Gudang Kecil belum dikonfigurasi, tidak bisa menyelesaikan pesanan.');
        }

        return (string) $locationId;
    }
}
