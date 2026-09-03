<?php

namespace Modules\Product\Services;

use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Attribute;
use Modules\Product\Repositories\CategoryAttributeRepository;

class CategoryFormAttributeService
{
    public function __construct(private CategoryAttributeRepository $repository) {}

    public function categoryExists(int $categoryId): bool
    {
        return $this->repository->exists($categoryId);
    }

    public function categoryIsLeaf(int $categoryId): bool
    {
        return $this->repository->isLeaf($categoryId);
    }

    public function formAttributes(int $categoryId): array
    {
        return $this->repository->formAttributes($categoryId);
    }

    public function createAttribute(int $categoryId, array $data): Attribute
    {
        return DB::transaction(function () use ($categoryId, $data) {
            $attribute = $this->repository->createAttribute($data['name'], $data['type']);

            $this->repository->linkAttribute($categoryId, $attribute->id);

            return $attribute;
        });
    }

    public function deleteAttribute(int $categoryId, int $attributeId): bool
    {
        $link = $this->repository->findLink($categoryId, $attributeId);

        if (! $link) {
            return false;
        }

        DB::transaction(function () use ($link, $attributeId) {
            $this->repository->deleteLink($link);

            if (! $this->repository->attributeStillLinked($attributeId)) {
                $this->repository->deleteAttribute($attributeId);
            }
        });

        return true;
    }
}
