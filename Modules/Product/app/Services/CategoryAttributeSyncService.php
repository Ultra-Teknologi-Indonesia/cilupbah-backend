<?php

namespace Modules\Product\Services;

use Modules\Channel\Services\ChannelListingValidator;
use Modules\Product\Repositories\CategoryAttributeSyncRepository;

class CategoryAttributeSyncService
{
    public function __construct(private CategoryAttributeSyncRepository $repository) {}

    public function materializeFromChannels(int $categoryId): int
    {
        $channelCatIds = $this->repository->channelCategoryIds($categoryId);

        if ($channelCatIds->isEmpty()) {
            return 0;
        }

        $system = array_map('mb_strtolower', ChannelListingValidator::SYSTEM_ATTRIBUTES);

        $channelAttrs = $this->repository->channelAttributes($channelCatIds);

        $added = 0;

        foreach ($channelAttrs as $ca) {
            if (in_array(mb_strtolower((string) $ca->external_id), $system, true)) {
                continue;
            }

            $attributeId = $this->resolveInternalAttribute($ca);

            $added += $this->upsertCategoryAttribute($categoryId, $attributeId, (bool) $ca->is_required);
            $this->materializeOptions($attributeId, $ca->id);
        }

        return $added;
    }

    public function materializeAllMapped(): array
    {
        $categoryIds = $this->repository->allMappedCategoryIds();

        $result = [];
        foreach ($categoryIds as $cid) {
            $result[(int) $cid] = $this->materializeFromChannels((int) $cid);
        }

        return $result;
    }

    private function resolveInternalAttribute(object $ca): int
    {
        $mappedId = $this->repository->mappedInternalAttributeId($ca->id);
        if ($mappedId) {
            return $mappedId;
        }

        $name = (string) ($ca->name ?: $ca->external_id);
        $type = $ca->is_sale_prop ? 'sales' : 'spec';

        $attributeId = $this->repository->findOrCreateAttribute($name, $type);
        $this->repository->linkAttributeToChannel($attributeId, $ca->id);

        return $attributeId;
    }

    private function upsertCategoryAttribute(int $categoryId, int $attributeId, bool $isRequired): int
    {
        $existing = $this->repository->findCategoryAttribute($categoryId, $attributeId);

        if ($existing) {
            if ($isRequired && ! $existing->is_required) {
                $this->repository->markCategoryAttributeRequired($existing);
            }

            return 0;
        }

        $this->repository->createCategoryAttribute($categoryId, $attributeId, $isRequired);

        return 1;
    }

    private function materializeOptions(int $attributeId, string $channelAttributeId): void
    {
        $opts = $this->repository->channelAttributeOptions($channelAttributeId);

        foreach ($opts as $opt) {
            $value = (string) ($opt->name ?: $opt->external_id);
            if ($value === '') {
                continue;
            }

            $this->repository->ensureAttributeOption($attributeId, $value);
        }
    }
}
