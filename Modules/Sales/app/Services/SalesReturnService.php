<?php

namespace Modules\Sales\Services;

use Modules\Sales\Repositories\SalesReturnRepository;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Exceptions\InvalidReturnStateException;
use Modules\Sales\Jobs\AdminAlertJob;
use Modules\Inbound\Services\InboundService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SalesReturnService
{
    public function __construct(
        protected SalesReturnRepository $returnRepository,
        protected InboundService $inboundService,
        protected SalesReturnSettingService $settings
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->returnRepository->getAllPaginated($limit);
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
        return DB::transaction(function () use ($data) {
            $data['return_number'] = $data['return_number'] ?? 'RET-' . now()->format('Ymd') . '-' . Str::upper(Str::random(4));
            $data['status'] = SalesReturn::STATUS_PENDING;
            $data['source'] = $data['source'] ?? SalesReturn::SOURCE_MANUAL;

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
            'order_id'      => $order->id,
            'location_id'   => $locationId,
            'source'        => SalesReturn::SOURCE_MANUAL,
            'customer_name' => $order->customer_name ?? null,
            'reason'        => $reason ?: 'Cancel diterima setelah paket dikirim',
            'created_by'    => $createdBy,
            'items'         => $items,
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
            'created_by'        => $payload['created_by'] ?? 'system:' . $source . '-webhook',
            'items'             => $items,
        ]);
    }

    public function accept(string $id, array $data): SalesReturn
    {
        return DB::transaction(function () use ($id, $data) {
            $return = $this->returnRepository->findByIdForUpdate($id);

            if (! $return) {
                throw new ModelNotFoundException('Sales return tidak ditemukan.');
            }

            if ($return->status !== SalesReturn::STATUS_PENDING) {
                throw new InvalidReturnStateException("Return sudah berstatus {$return->status}.");
            }

            $this->returnRepository->updateStatus($return, SalesReturn::STATUS_ACCEPTED, $data['processed_by']);

            $inboundItems = $return->items->map(fn ($item) => [
                'item_id'      => $item->item_id,
                'expected_qty' => $item->qty,
            ])->toArray();

            $inbound = $this->inboundService->receiveFromSalesReturn([
                'location_id'      => $this->settings->restockLocationId() ?? $return->location_id,
                'reference_number' => $return->return_number,
                'source_id'        => $return->id,
                'expected_date'    => now()->toDateString(),
                'created_by'       => $data['processed_by'],
                'items'            => $inboundItems,
            ]);

            if ($this->settings->autoReceive()) {
                $conditionByItem = $return->items->keyBy('item_id');

                $receiveItems = $inbound->items->map(fn ($item) => [
                    'inbound_item_id' => $item->id,
                    'qty'             => $item->expected_qty,
                    'condition'       => $conditionByItem[$item->item_id]->condition ?? 'GOOD',
                ])->toArray();

                $this->inboundService->receive($inbound->id, [
                    'received_by' => $data['processed_by'],
                    'items'       => $receiveItems,
                ]);
            }

            return $this->getById($id);
        });
    }

    public function reject(string $id, array $data): SalesReturn
    {
        return DB::transaction(function () use ($id, $data) {
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
