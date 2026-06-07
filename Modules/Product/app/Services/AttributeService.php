<?php

namespace Modules\Product\Services;

use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Product\Models\Attribute;
use Modules\Product\Repositories\AttributeRepository;

class AttributeService
{
    protected AttributeRepository $repository;

    public function __construct(AttributeRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getPaginatedAttributes(int $perPage = 10): LengthAwarePaginator
    {
        return $this->repository->getPaginated($perPage);
    }

    public function getAllAttributes(): Collection
    {
        return $this->repository->getAll();
    }

    public function getAttributeById(int $id): ?Attribute
    {
        $attribute = $this->repository->findById($id);
        if (!$attribute) {
            throw new Exception("Attribute not found");
        }
        return $attribute;
    }

    public function createAttribute(array $data): Attribute
    {
        return $this->repository->create($data);
    }

    public function updateAttribute(int $id, array $data): Attribute
    {
        $attribute = $this->getAttributeById($id);
        return $this->repository->update($attribute, $data);
    }

    public function deleteAttribute(int $id): bool
    {
        $attribute = $this->getAttributeById($id);
        // Additional validation can be added here if attributes are linked to specifications
        return $this->repository->delete($attribute);
    }

    public function mapToChannel(int $attributeId, array $channelAttributeIds): Attribute
    {
        $attribute = $this->getAttributeById($attributeId);
        $attribute->channelAttributes()->sync($channelAttributeIds);
        
        return $attribute->load('channelAttributes');
    }

    public function mapOptionToChannel(int $optionId, array $channelAttributeOptionIds): \Modules\Product\Models\AttributeOption
    {
        $option = \Modules\Product\Models\AttributeOption::find($optionId);
        if (!$option) {
            throw new Exception("Attribute Option not found");
        }
        
        $option->channelAttributeOptions()->sync($channelAttributeOptionIds);
        
        return $option->load('channelAttributeOptions');
    }
}
