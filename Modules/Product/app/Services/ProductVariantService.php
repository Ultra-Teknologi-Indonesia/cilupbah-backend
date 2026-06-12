<?php

namespace Modules\Product\Services;

use DomainException;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Repositories\ProductVariantRepository;

class ProductVariantService
{
    public function __construct(
        private readonly ProductVariantRepository $repository,
    ) {
    }

    /** Jubelio: GET /variations. */
    public function list(): LengthAwarePaginator
    {
        return $this->repository->paginate();
    }

    public function find(string $id): ?ProductVariant
    {
        return $this->repository->findById($id);
    }

    /**
     * Jubelio: DELETE /inventory/items/item-variant.
     * Guard: tolak hapus bila varian masih memiliki stok.
     */
    public function deleteVariant(ProductVariant $variant): void
    {
        $onHand = (int) $variant->inventories()->sum('on_hand');
        if ($onHand > 0) {
            throw new DomainException(
                "Varian masih memiliki stok ({$onHand} unit) dan tidak dapat dihapus."
            );
        }

        $this->repository->delete($variant);
    }
}
