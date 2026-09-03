<?php

namespace Modules\Inventory\Repositories;

use App\Support\WarehouseAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Modules\Inventory\Models\RackImportBatch;
use Modules\Inventory\Models\RackImportRow;

class RackImportBatchRepository
{
    public function paginateBatches(): LengthAwarePaginator
    {
        $query = RackImportBatch::query()
            ->with('executor:id,name')
            ->when(request('state'), fn ($q, $state) => $q->where('state', $state))
            ->orderByDesc('created_at');
        $this->scopeBatchVisibility($query);

        return $query->paginate((int) request('per_page', 20))
            ->appends(request()->query());
    }

    public function find(string $id): ?RackImportBatch
    {
        $query = RackImportBatch::with('executor:id,name');
        $this->scopeBatchVisibility($query);

        return $query->find($id);
    }

    public function paginateRows(RackImportBatch $batch): LengthAwarePaginator
    {
        $query = RackImportRow::where('batch_id', $batch->id);
        WarehouseAccess::apply($query, 'location_id');

        return $query
            ->when(request('status'), fn ($q, $status) => $q->where('status', $status))
            ->allowedSearch('raw_sku', 'raw_bin', 'raw_location')
            ->orderBy('row_no')
            ->paginate((int) request('per_page', 20))
            ->appends(request()->query());
    }

    private function scopeBatchVisibility($query): void
    {
        if (! WarehouseAccess::isRestricted()) {
            return;
        }

        $query->where(function ($visible) {
            $visible->where('executed_by', Auth::id())
                ->orWhereHas('rows', fn ($rows) => WarehouseAccess::apply($rows, 'location_id'));
        });
    }
}
