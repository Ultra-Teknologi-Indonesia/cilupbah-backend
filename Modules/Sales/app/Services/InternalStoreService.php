<?php

namespace Modules\Sales\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\InternalStore;
use Modules\Sales\Repositories\InternalStoreRepository;

class InternalStoreService
{
    public function __construct(private InternalStoreRepository $repository)
    {
    }

    public function list(int $perPage = 20)
    {
        return $this->repository->getAllPaginated($perPage);
    }

    public function all()
    {
        return $this->repository->getAllActive();
    }

    public function find(string $id): ?InternalStore
    {
        return $this->repository->findById($id);
    }

    public function create(array $data, ?UploadedFile $logo = null): InternalStore
    {
        return DB::transaction(function () use ($data, $logo) {
            $store = new InternalStore();
            $store->code = $this->repository->nextCode();
            $store->name = $data['name'];
            $store->is_active = (bool) ($data['is_active'] ?? true);
            $store->save();

            if ($logo) {
                $store->addMedia($logo)->toMediaCollection('logo');
            }

            return $store->refresh();
        });
    }

    public function update(string $id, array $data, ?UploadedFile $logo = null): InternalStore
    {
        return DB::transaction(function () use ($id, $data, $logo) {
            $store = InternalStore::findOrFail($id);
            if (array_key_exists('name', $data)) {
                $store->name = $data['name'];
            }
            if (array_key_exists('is_active', $data)) {
                $store->is_active = (bool) $data['is_active'];
            }
            $store->save();

            if ($logo) {
                $store->clearMediaCollection('logo');
                $store->addMedia($logo)->toMediaCollection('logo');
            }

            return $store->refresh();
        });
    }

    public function delete(string $id): bool
    {
        $store = InternalStore::findOrFail($id);
        return (bool) $store->delete();
    }

    public function replaceLogo(string $id, UploadedFile $logo): InternalStore
    {
        $store = InternalStore::findOrFail($id);
        $store->clearMediaCollection('logo');
        $store->addMedia($logo)->toMediaCollection('logo');
        return $store->refresh();
    }

    public function deleteLogo(string $id): InternalStore
    {
        $store = InternalStore::findOrFail($id);
        $store->clearMediaCollection('logo');
        return $store->refresh();
    }
}
