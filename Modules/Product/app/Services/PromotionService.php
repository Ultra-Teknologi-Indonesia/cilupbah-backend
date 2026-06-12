<?php

namespace Modules\Product\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Product\Models\Promotion;
use Modules\Product\Repositories\PromotionRepository;

class PromotionService
{
    public function __construct(
        private readonly PromotionRepository $repository,
    ) {
    }

    public function list(): LengthAwarePaginator
    {
        return $this->repository->paginate();
    }

    public function find(string $id): ?Promotion
    {
        return $this->repository->findById($id);
    }

    public function create(array $data): Promotion
    {
        $attributes = [
            'name' => $data['name'],
            'type' => $data['type'],
            'value' => $data['value'],
            'start_at' => $data['start_at'] ?? null,
            'end_at' => $data['end_at'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ];

        return $this->repository->create($attributes, $data['product_ids'] ?? []);
    }

    public function delete(Promotion $promotion): void
    {
        $this->repository->delete($promotion);
    }
}
