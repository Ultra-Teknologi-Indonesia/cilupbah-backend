<?php

namespace Modules\Sales\Services;

use Modules\Sales\Repositories\SalesReturnRepository;
use Modules\Sales\Models\SalesReturn;
use Modules\Inbound\Services\InboundService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SalesReturnService
{
    public function __construct(
        protected SalesReturnRepository $returnRepository,
        protected InboundService $inboundService
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

    /** Flow [D] — return with invoice/order, or [E] — return without invoice */
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

            return $return->load('items.product:id,sku,product_id', 'items.product.product:id,name');
        });
    }

    /** Flow [F] accept — marketplace return accepted, stock masuk */
    public function accept(string $id, array $data): SalesReturn
    {
        return DB::transaction(function () use ($id, $data) {
            $return = $this->returnRepository->findByIdForUpdate($id);

            if (! $return) {
                throw new \Exception('Sales return tidak ditemukan.');
            }

            if ($return->status !== SalesReturn::STATUS_PENDING) {
                throw new \Exception("Return sudah berstatus {$return->status}.");
            }

            $this->returnRepository->updateStatus($return, SalesReturn::STATUS_ACCEPTED, $data['processed_by']);

            $inboundItems = $return->items->map(fn ($item) => [
                'item_id'      => $item->item_id,
                'expected_qty' => $item->qty,
            ])->toArray();

            $inbound = $this->inboundService->receiveFromSalesReturn([
                'location_id'      => $return->location_id,
                'reference_number' => $return->return_number,
                'source_id'        => $return->id,
                'expected_date'    => now()->toDateString(),
                'created_by'       => $data['processed_by'],
                'items'            => $inboundItems,
            ]);

            return $this->getById($id);
        });
    }

    /** Flow [F] reject — marketplace return rejected, stock tidak berubah */
    public function reject(string $id, array $data): SalesReturn
    {
        return DB::transaction(function () use ($id, $data) {
            $return = $this->returnRepository->findByIdForUpdate($id);

            if (! $return) {
                throw new \Exception('Sales return tidak ditemukan.');
            }

            if ($return->status !== SalesReturn::STATUS_PENDING) {
                throw new \Exception("Return sudah berstatus {$return->status}.");
            }

            $return->update(['notes' => $data['reason'] ?? $return->notes]);
            $this->returnRepository->updateStatus($return, SalesReturn::STATUS_REJECTED, $data['processed_by']);

            return $this->getById($id);
        });
    }

    /** Flow [F] complete — mark as complete (not a return) */
    public function complete(string $id, array $data): SalesReturn
    {
        return DB::transaction(function () use ($id, $data) {
            $return = $this->returnRepository->findByIdForUpdate($id);

            if (! $return) {
                throw new \Exception('Sales return tidak ditemukan.');
            }

            if (! in_array($return->status, [SalesReturn::STATUS_PENDING, SalesReturn::STATUS_ACCEPTED])) {
                throw new \Exception("Return berstatus {$return->status}, tidak bisa di-complete.");
            }

            $this->returnRepository->updateStatus($return, SalesReturn::STATUS_COMPLETED, $data['processed_by']);

            return $this->getById($id);
        });
    }
}
