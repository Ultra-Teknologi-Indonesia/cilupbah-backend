<?php

namespace Modules\Sales\Services;

use Modules\Sales\Repositories\SalesReturnRepository;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Exceptions\InvalidReturnStateException;
use Modules\Sales\Jobs\AdminAlertJob;
use Modules\Inbound\Services\InboundService;
use Modules\Notification\Services\NotificationDispatcher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SalesReturnService
{
    public const NOTIF_PERMISSION = 'view-retur-penjualan';

    public function __construct(
        protected SalesReturnRepository $returnRepository,
        protected InboundService $inboundService,
        protected SalesReturnSettingService $settings,
        protected NotificationDispatcher $notifications,
    ) {}

    private function returnLink(string $id): string
    {
        return "/dashboard/barang-masuk/retur/{$id}";
    }

    public function getAllPaginated(int $limit = 10)
    {
        return $this->returnRepository->getAllPaginated($limit);
    }

    public function getReportPaginated(array $filters, int $limit = 10)
    {
        return $this->returnRepository->getReportPaginated($filters, $limit);
    }

    public function getAppeals(SalesReturn $return)
    {
        return $this->returnRepository->getAppeals($return);
    }

    public function getUnprocessedMarketplace(int $limit = 10)
    {
        return $this->returnRepository->getUnprocessedMarketplace($limit);
    }

    public function getById(string $id): ?SalesReturn
    {
        return $this->returnRepository->findById($id);
    }

    public function getUnpaidReturns(int $limit = 10)
    {
        return $this->returnRepository->getUnpaidReturns($limit);
    }

    public function getAllReturnItems(int $limit = 10)
    {
        return $this->returnRepository->getAllReturnItems($limit);
    }

    public function getRejectedReturnItems(int $limit = 10)
    {
        return $this->returnRepository->getRejectedReturnItems($limit);
    }

    public function getResolvedReturnItems(int $limit = 10)
    {
        return $this->returnRepository->getResolvedReturnItems($limit);
    }

    public function create(array $data): SalesReturn
    {
        $return = DB::transaction(function () use ($data) {
            $data['return_number'] = $data['return_number'] ?? 'RET-' . now()->format('Ymd') . '-' . Str::upper(Str::random(4));
            $data['status'] = SalesReturn::STATUS_PENDING;
            $data['source'] = $data['source'] ?? SalesReturn::SOURCE_MANUAL;
            $data['reason_category'] = $data['reason_category'] ?? SalesReturn::REASON_CATEGORY_OTHER;

            $return = $this->returnRepository->create($data);

            foreach ($data['items'] as $itemData) {
                $itemData['sales_return_id'] = $return->id;
                $this->returnRepository->createItem($itemData);
            }

            $return->load('items');

            if ($this->settings->autoAccept()) {
                try {
                    $this->accept($return->id, ['processed_by' => $data['created_by']]);
                } catch (\Throwable $e) {

                    Log::warning("Auto-accept retur {$return->return_number} gagal: {$e->getMessage()}");

                    AdminAlertJob::dispatch(
                        "Auto-accept sales return gagal: {$return->return_number}",
                        $e->getMessage(),
                        ['sales_return_id' => $return->id, 'return_number' => $return->return_number]
                    )->onQueue(config('queue.names.failed_jobs'));
                }
            }

            return $this->getById($return->id)
                ?? $return->load('items.product:id,sku,product_id', 'items.product.product:id,name');
        });

        $skuCount = $return->items->count();
        $sourceLabel = $return->source === SalesReturn::SOURCE_MARKETPLACE
            ? 'marketplace'
            : ($return->reason_category === SalesReturn::REASON_CATEGORY_CANCEL_SHIPPED ? 'cancel-shipped' : 'manual');
        $this->notifications->toPermission(self::NOTIF_PERMISSION, [
            'type' => 'sales_return_new',
            'title' => 'Retur baru masuk',
            'message' => "{$return->return_number} ({$sourceLabel}) berisi {$skuCount} SKU perlu diproses.",
            'data' => [
                'sales_return_id' => $return->id,
                'return_number' => $return->return_number,
                'source' => $return->source,
                'link' => $this->returnLink($return->id),
            ],
        ]);

        return $return;
    }

    public function createFromCancelledShipped(SalesOrder $order, ?string $reason, string $createdBy): ?SalesReturn
    {
        $locationId = $order->location_id ?? $this->settings->restockLocationId();
        if (! $locationId) {
            Log::warning('createFromCancelledShipped: lokasi restock tidak dapat ditentukan.', [
                'order_id' => $order->id,
            ]);
            return null;
        }

        $order->loadMissing('items');

        $items = $order->items
            ->filter(fn ($it) => $it->item_id && (float) $it->qty_in_base > 0)
            ->map(fn ($it) => [
                'item_id'   => $it->item_id,
                'qty'       => (int) $it->qty_in_base,
                'condition' => 'GOOD',
            ])
            ->values()
            ->toArray();

        if (empty($items)) {
            Log::warning('createFromCancelledShipped: order tanpa item valid.', [
                'order_id' => $order->id,
            ]);
            return null;
        }

        return $this->create([
            'order_id'        => $order->id,
            'location_id'     => $locationId,
            'source'          => SalesReturn::SOURCE_MANUAL,
            'customer_name'   => $order->customer_name ?? null,
            'reason'          => $reason ?: 'Cancel diterima setelah paket dikirim',
            'reason_category' => SalesReturn::REASON_CATEGORY_CANCEL_SHIPPED,
            'created_by'      => $createdBy,
            'items'           => $items,
        ]);
    }

    public function createFromChannel(array $payload): ?SalesReturn
    {
        $source = (string) $payload['source'];
        $channelOrderId = (string) $payload['channel_order_id'];

        $channelReturnId = isset($payload['channel_return_id']) && $payload['channel_return_id'] !== ''
            ? $source . ':' . $payload['channel_return_id']
            : null;

        if ($channelReturnId && $this->returnRepository->existsByChannelReturn(SalesReturn::SOURCE_MARKETPLACE, $channelReturnId)) {
            return null;
        }

        $order = SalesOrder::with('items')
            ->where('source', $source)
            ->where(function ($q) use ($channelOrderId) {
                $q->where('channel_order_no', $channelOrderId)
                  ->orWhere('salesorder_no', $channelOrderId)
                  ->orWhere('salesorder_no', 'like', '%-' . $channelOrderId);
            })
            ->first();

        if (! $order) {
            Log::warning('Retur marketplace dilewati: order lokal tidak ditemukan.', [
                'source' => $source,
                'channel_order_id' => $channelOrderId,
                'channel_return_id' => $channelReturnId,
            ]);
            return null;
        }

        if (! $channelReturnId && $this->returnRepository->existsMarketplaceForOrder($order->id)) {
            return null;
        }

        $locationId = $order->location_id ?? $this->settings->restockLocationId();
        if (! $locationId) {
            Log::warning('Retur marketplace dilewati: lokasi restock tidak dapat ditentukan.', [
                'source' => $source,
                'order_id' => $order->id,
            ]);
            return null;
        }

        $items = $order->items
            ->filter(fn ($it) => $it->item_id && (float) $it->qty_in_base > 0)
            ->map(fn ($it) => [
                'item_id'   => $it->item_id,
                'qty'       => (int) $it->qty_in_base,
                'condition' => 'GOOD',
            ])
            ->values()
            ->toArray();

        if (empty($items)) {
            Log::warning('Retur marketplace dilewati: order tanpa item valid.', [
                'source' => $source,
                'order_id' => $order->id,
            ]);
            return null;
        }

        return $this->create([
            'order_id'          => $order->id,
            'location_id'       => $locationId,
            'source'            => SalesReturn::SOURCE_MARKETPLACE,
            'channel_return_id' => $channelReturnId,
            'channel_shop_id'   => $payload['channel_shop_id'] ?? null,
            'customer_name'     => $order->customer_name ?? null,
            'reason'            => $payload['reason'] ?? 'Retur dari marketplace',

            'reason_category'   => SalesReturn::REASON_CATEGORY_COMPLAINT,
            'created_by'        => $payload['created_by'] ?? 'system:' . $source . '-webhook',
            'items'             => $items,
        ]);
    }

    public function accept(string $id, array $data): SalesReturn
    {
        $return = DB::transaction(function () use ($id, $data) {
            $return = $this->returnRepository->findByIdForUpdate($id);

            if (! $return) {
                throw new ModelNotFoundException('Sales return tidak ditemukan.');
            }

            if ($return->status !== SalesReturn::STATUS_PENDING) {
                throw new InvalidReturnStateException("Return sudah berstatus {$return->status}.");
            }

            $this->returnRepository->updateStatus($return, SalesReturn::STATUS_ACCEPTED, $data['processed_by']);

            $approvedByItemId = [];
            foreach (($data['items'] ?? []) as $line) {
                if (isset($line['item_id']) && array_key_exists('approved_qty', $line) && $line['approved_qty'] !== null) {
                    $approvedByItemId[$line['item_id']] = (int) $line['approved_qty'];
                }
            }

            foreach ($return->items as $item) {
                $requested = $approvedByItemId[$item->item_id] ?? (int) $item->qty;
                $approved = max(0, min($requested, (int) $item->qty));

                if ((int) ($item->approved_qty ?? -1) !== $approved) {
                    $item->approved_qty = $approved;
                    $item->save();
                }
            }

            // Bundle tak menyimpan stok fisik: restock diarahkan ke KOMPONEN
            // (qty x qty-komponen). Non-bundle tetap 1:1. Cegah baris inventory
            // hantu untuk SKU bundle + memastikan komponen benar-benar di-restock.
            $productRepo = app(\Modules\Product\Repositories\ProductRepository::class);
            $qtyByItem = [];
            $conditionByItem = [];

            foreach ($return->items->filter(fn ($item) => $item->approvedQty() > 0) as $item) {
                $components = $productRepo->bundleComponentsForVariant($item->item_id);
                $lines = $components ?? [['variant_id' => $item->item_id, 'qty' => 1]];

                foreach ($lines as $line) {
                    $vid = $line['variant_id'];
                    $qtyByItem[$vid] = ($qtyByItem[$vid] ?? 0) + $item->approvedQty() * (int) $line['qty'];
                    $conditionByItem[$vid] ??= ($item->condition ?? 'GOOD');
                }
            }

            $inboundItems = [];
            foreach ($qtyByItem as $vid => $qty) {
                $inboundItems[] = ['item_id' => $vid, 'expected_qty' => $qty];
            }

            if (! empty($inboundItems)) {
                $inbound = $this->inboundService->receiveFromSalesReturn([
                    'location_id'      => $this->settings->restockLocationId() ?? $return->location_id,
                    'reference_number' => $return->return_number,
                    'source_id'        => $return->id,
                    'expected_date'    => now()->toDateString(),
                    'created_by'       => $data['processed_by'],
                    'items'            => $inboundItems,
                ]);

                $receiverId = auth()->id()
                    ?? (Str::isUuid((string) ($data['processed_by'] ?? '')) ? $data['processed_by'] : null);

                if ($this->settings->autoReceive() && $receiverId) {
                    $receiveItems = $inbound->items->map(fn ($item) => [
                        'inbound_item_id' => $item->id,
                        'qty'             => $item->expected_qty,
                        'condition'       => $conditionByItem[$item->item_id] ?? 'GOOD',
                    ])->toArray();

                    $this->inboundService->receive($inbound->id, [
                        'received_by' => $receiverId,
                        'items'       => $receiveItems,
                    ]);
                }
            }

            return $this->getById($id);
        });

        if ($return && $return->source === SalesReturn::SOURCE_MARKETPLACE) {
            $this->notifications->toPermission(self::NOTIF_PERMISSION, [
                'type' => 'sales_return_marketplace_decision',
                'title' => 'Marketplace menyetujui retur',
                'message' => "Retur {$return->return_number} disetujui, stok akan direstock.",
                'data' => [
                    'sales_return_id' => $return->id,
                    'return_number' => $return->return_number,
                    'decision' => 'accepted',
                    'link' => $this->returnLink($return->id),
                ],
            ], excludeUserIds: array_filter([$data['processed_by'] ?? null]));
        }

        return $return;
    }

    public function reject(string $id, array $data): SalesReturn
    {
        $return = DB::transaction(function () use ($id, $data) {
            $return = $this->returnRepository->findByIdForUpdate($id);

            if (! $return) {
                throw new ModelNotFoundException('Sales return tidak ditemukan.');
            }

            if ($return->status !== SalesReturn::STATUS_PENDING) {
                throw new InvalidReturnStateException("Return sudah berstatus {$return->status}.");
            }

            $return->update(['notes' => $data['reason'] ?? $return->notes]);
            $this->returnRepository->updateStatus($return, SalesReturn::STATUS_REJECTED, $data['processed_by']);

            return $this->getById($id);
        });

        if ($return && $return->source === SalesReturn::SOURCE_MARKETPLACE) {
            $reason = $data['reason'] ?? null;
            $reasonSuffix = $reason ? " Alasan: {$reason}" : '';
            $this->notifications->toPermission(self::NOTIF_PERMISSION, [
                'type' => 'sales_return_marketplace_decision',
                'title' => 'Marketplace menolak retur',
                'message' => "Retur {$return->return_number} ditolak.{$reasonSuffix}",
                'data' => [
                    'sales_return_id' => $return->id,
                    'return_number' => $return->return_number,
                    'decision' => 'rejected',
                    'reason' => $reason,
                    'link' => $this->returnLink($return->id),
                ],
            ], excludeUserIds: array_filter([$data['processed_by'] ?? null]));
        }

        return $return;
    }

    public function buildChannelOnlinePutawayReport(?string $dateFrom, ?string $dateTo, ?string $locationId): array
    {
        $dateFrom = $dateFrom ?: now()->toDateString();
        $dateTo = $dateTo ?: $dateFrom;

        $returns = SalesReturn::query()
            ->whereIn('status', [SalesReturn::STATUS_ACCEPTED, SalesReturn::STATUS_COMPLETED])
            ->whereDate('processed_at', '>=', $dateFrom)
            ->whereDate('processed_at', '<=', $dateTo)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->with([
                'items.product:id,product_id,sku',
                'items.product.product:id,product_name',
                'order:id,salesorder_no,channel_order_no',
                'location:id,location_name',
                'inbounds.putaways',
            ])
            ->orderByDesc('processed_at')
            ->get();

        $placed = collect();
        $unplaced = collect();

        foreach ($returns as $return) {
            $putawayDate = $return->inbounds
                ->flatMap(fn ($inbound) => $inbound->putaways)
                ->where('status', 'COMPLETED')
                ->max('completed_at');

            $isPlaced = $putawayDate
                && $return->processed_at
                && \Carbon\Carbon::parse($putawayDate)->toDateString() === $return->processed_at->toDateString();

            foreach ($return->items as $item) {
                $row = [
                    $return->location?->location_name,
                    $return->return_tracking_number,
                    $return->order?->salesorder_no,
                    $return->order?->channel_order_no,
                    $return->processed_at?->format('d/m/Y H:i'),
                    $item->product?->sku,
                    $item->product?->product?->product_name,
                    $item->qty,
                    str_replace('RET-', 'SR-', $return->return_number),
                    $return->created_by,
                    $return->processed_by,
                ];

                if ($isPlaced) {
                    $placed->push($row);
                } else {
                    $unplaced->push($row);
                }
            }
        }

        return compact('placed', 'unplaced');
    }

    public function complete(string $id, array $data): SalesReturn
    {
        return DB::transaction(function () use ($id, $data) {
            $return = $this->returnRepository->findByIdForUpdate($id);

            if (! $return) {
                throw new ModelNotFoundException('Sales return tidak ditemukan.');
            }

            if ($return->status === SalesReturn::STATUS_COMPLETED) {
                return $this->getById($id); 
            }

            if ($return->status !== SalesReturn::STATUS_ACCEPTED) {
                throw new InvalidReturnStateException("Return berstatus {$return->status}, harus di-accept dulu sebelum complete.");
            }

            $this->returnRepository->updateStatus($return, SalesReturn::STATUS_COMPLETED, $data['processed_by']);

            return $this->getById($id);
        });
    }
}
