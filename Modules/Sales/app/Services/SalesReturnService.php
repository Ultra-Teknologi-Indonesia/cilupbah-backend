<?php

namespace Modules\Sales\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Inbound\Services\InboundService;
use Modules\Inventory\Models\ImpexActivity;
use Modules\Inventory\Services\ImpexActivityService;
use Modules\Notification\Services\NotificationDispatcher;
use Modules\Product\Repositories\ProductRepository;
use Modules\Sales\Exceptions\InvalidReturnStateException;
use Modules\Sales\Exports\ReturnChannelOnlineExport;
use Modules\Sales\Exports\SalesReturnReportExport;
use Modules\Sales\Jobs\AdminAlertJob;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Repositories\SalesReturnRepository;

class SalesReturnService
{
    public const NOTIF_PERMISSION = 'view-retur-penjualan';

    public function __construct(
        protected SalesReturnRepository $returnRepository,
        protected InboundService $inboundService,
        protected SalesReturnSettingService $settings,
        protected NotificationDispatcher $notifications,
        protected ImpexActivityService $activityService,
    ) {}

    public function prepareExport(string $type, array $filters, $userId = null): array
    {
        if ($type === 'channel_online') {
            $dateFrom = $filters['date_from'] ?? now()->toDateString();
            $dateTo = $filters['date_to'] ?? $dateFrom;

            $export = new ReturnChannelOnlineExport(
                dateFrom: $dateFrom,
                dateTo: $dateTo,
                locationId: $filters['location_id'] ?? null,
                status: $filters['status'] ?? null,
            );

            $filename = sprintf('retur-channel-online-%s-%s.xlsx', $dateFrom, $dateTo);
            $label = 'Export Retur Channel Online';
        } else {
            $dateFrom = $filters['date_from'] ?? null;
            $dateTo = $filters['date_to'] ?? null;

            $export = new SalesReturnReportExport(
                dateFrom: $dateFrom,
                dateTo: $dateTo,
                locationId: $filters['location_id'] ?? null,
                channelShopId: $filters['channel_shop_id'] ?? null,
                status: $filters['status'] ?? null,
                source: $filters['source'] ?? null,
                reasonCategory: $filters['reason_category'] ?? null,
                marketplaceDecision: $filters['marketplace_decision'] ?? null,
            );

            $filename = sprintf('laporan-retur-%s-%s.xlsx', $dateFrom ?? 'semua', $dateTo ?? now()->toDateString());
            $label = 'Export Laporan Retur';
        }

        $this->activityService->recordCompleted(
            ImpexActivity::DIRECTION_EXPORT,
            $label,
            $userId,
        );

        return ['export' => $export, 'filename' => $filename];
    }

    private function returnLink(string $id): string
    {
        return "/dashboard/barang-masuk/retur/{$id}";
    }

    public function getAllPaginated(int $limit = 10)
    {
        return $this->returnRepository->getAllPaginated($limit);
    }

    public function filterOptions(): array
    {
        return $this->returnRepository->filterOptions();
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
            $data['return_number'] = $data['return_number'] ?? 'RET-'.now()->format('Ymd').'-'.Str::upper(Str::random(4));
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
                'item_id' => $it->item_id,
                'qty' => (int) $it->qty_in_base,
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
            'order_id' => $order->id,
            'location_id' => $locationId,
            'source' => SalesReturn::SOURCE_MANUAL,
            'customer_name' => $order->customer_name ?? null,
            'reason' => $reason ?: 'Cancel diterima setelah paket dikirim',
            'reason_category' => SalesReturn::REASON_CATEGORY_CANCEL_SHIPPED,
            'created_by' => $createdBy,
            'items' => $items,
        ]);
    }

    public function createFromChannel(array $payload): ?SalesReturn
    {
        $source = (string) $payload['source'];
        $channelOrderId = (string) $payload['channel_order_id'];

        $channelReturnId = isset($payload['channel_return_id']) && $payload['channel_return_id'] !== ''
            ? $source.':'.$payload['channel_return_id']
            : null;

        if ($channelReturnId && $this->returnRepository->existsByChannelReturn(SalesReturn::SOURCE_MARKETPLACE, $channelReturnId)) {
            $existing = SalesReturn::where('source', SalesReturn::SOURCE_MARKETPLACE)
                ->where('channel_return_id', $channelReturnId)
                ->first();

            return $existing ? $this->applyChannelStatus($existing, $payload) : null;
        }

        $order = SalesOrder::with('items')
            ->where('source', $source)
            ->where(function ($q) use ($channelOrderId) {
                $q->where('channel_order_no', $channelOrderId)
                    ->orWhere('salesorder_no', $channelOrderId)
                    ->orWhere('salesorder_no', 'like', '%-'.$channelOrderId);
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
            $existing = SalesReturn::where('order_id', $order->id)
                ->where('source', SalesReturn::SOURCE_MARKETPLACE)
                ->latest('created_at')
                ->first();

            return $existing ? $this->applyChannelStatus($existing, $payload) : null;
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
                'item_id' => $it->item_id,
                'qty' => (int) $it->qty_in_base,
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

        $marketplaceRawStatus = $this->channelStatusFromPayload($payload);
        $marketplaceDecision = $this->channelDecisionFromPayload($source, $payload);

        return $this->create([
            'order_id' => $order->id,
            'location_id' => $locationId,
            'source' => SalesReturn::SOURCE_MARKETPLACE,
            'channel_return_id' => $channelReturnId,
            'channel_shop_id' => $payload['channel_shop_id'] ?? null,
            'customer_name' => $order->customer_name ?? null,
            'reason' => $payload['reason'] ?? 'Retur dari marketplace',
            'channel_reason_text' => $payload['channel_reason_text'] ?? null,
            'marketplace_raw_status' => $marketplaceRawStatus,
            'marketplace_decision' => $marketplaceDecision,
            'marketplace_decision_at' => $marketplaceDecision !== null ? now() : null,

            'reason_category' => SalesReturn::REASON_CATEGORY_COMPLAINT,
            'created_by' => $payload['created_by'] ?? 'system:'.$source.'-webhook',
            'items' => $items,
        ]);
    }

    private function applyChannelStatus(SalesReturn $return, array $payload): SalesReturn
    {
        $rawStatus = $this->channelStatusFromPayload($payload);
        if ($rawStatus === null) {
            return $return;
        }

        $source = (string) ($payload['source'] ?? $return->channel ?? $return->order?->source ?? '');
        $decision = $this->channelDecisionFromPayload($source, $payload);
        $update = [
            'detail_synced_at' => now(),
        ];

        if ($decision !== null && SalesReturn::shouldApplyMarketplaceDecision($return->marketplace_decision, $decision)) {
            $update['marketplace_raw_status'] = $rawStatus;

            if ($decision !== $return->marketplace_decision || $return->marketplace_decision_at === null) {
                $update['marketplace_decision'] = $decision;
                $update['marketplace_decision_at'] = now();
            }
        } elseif ($decision !== null && $decision === $return->marketplace_decision) {
            $update['marketplace_raw_status'] = $rawStatus;
        }

        if (! empty($payload['channel_reason_text'])) {
            $update['channel_reason_text'] = $payload['channel_reason_text'];
        }

        $return->update($update);

        return $return->refresh();
    }

    private function channelStatusFromPayload(array $payload): ?string
    {
        foreach ([
            'channel_status',
            'return_status',
            'reverse_status',
            'refund_status',
            'status',
        ] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function channelDecisionFromPayload(string $source, array $payload): ?string
    {
        $rawStatus = $this->channelStatusFromPayload($payload);
        if ($rawStatus === null || $source === '') {
            return null;
        }

        return SalesReturn::normalizeMarketplaceDecision($source, $rawStatus);
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

            $productRepo = app(ProductRepository::class);
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
                    'location_id' => $this->settings->restockLocationId() ?? $return->location_id,
                    'reference_number' => $return->return_number,
                    'source_id' => $return->id,
                    'expected_date' => now()->toDateString(),
                    'created_by' => $data['processed_by'],
                    'items' => $inboundItems,
                ]);

                $receiverId = auth()->id();
                if (! $receiverId) {
                    $candidate = (string) ($data['processed_by'] ?? '');
                    if (Str::isUuid($candidate) && User::where('id', $candidate)->exists()) {
                        $receiverId = $candidate;
                    }
                }
                if (! $receiverId) {
                    $receiverId = User::value('id');
                }
                if (! $receiverId) {
                    $systemUser = User::create([
                        'id' => (string) Str::uuid(),
                        'name' => 'System Auto-Receive',
                        'email' => 'system-return@cilupbah.internal',
                        'password' => bcrypt(Str::random(16)),
                    ]);
                    $receiverId = $systemUser->id;
                }

                $receiveItems = $inbound->items->map(fn ($item) => [
                    'inbound_item_id' => $item->id,
                    'qty' => $item->expected_qty,
                    'condition' => $conditionByItem[$item->item_id] ?? 'GOOD',
                ])->toArray();

                $this->inboundService->receive($inbound->id, [
                    'received_by' => $receiverId,
                    'idempotency_key' => "sales-return-auto-receive:{$return->id}",
                    'items' => $receiveItems,
                ]);
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

    public function buildChannelOnlinePutawayReport(?string $dateFrom, ?string $dateTo, ?string $locationId, ?string $status): array
    {
        $dateFrom = $dateFrom ?: now()->toDateString();
        $dateTo = $dateTo ?: $dateFrom;

        $returns = SalesReturn::query()
            ->when($status, function ($q, $s) {
                if ($s === 'unprocessed') {
                    $q->where('status', SalesReturn::STATUS_PENDING);
                } else {
                    $q->where('status', $s);
                }
            }, function ($q) {
                $q->whereIn('status', [SalesReturn::STATUS_ACCEPTED, SalesReturn::STATUS_COMPLETED]);
            })
            ->where(function ($q) use ($dateFrom, $dateTo, $status) {
                if ($status === 'unprocessed') {
                    $q->whereDate('created_at', '>=', $dateFrom)
                      ->whereDate('created_at', '<=', $dateTo);
                } else {
                    $q->whereDate('processed_at', '>=', $dateFrom)
                      ->whereDate('processed_at', '<=', $dateTo);
                }
            })
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
                && Carbon::parse($putawayDate)->toDateString() === $return->processed_at->toDateString();

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
