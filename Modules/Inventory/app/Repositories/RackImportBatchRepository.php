<?php

namespace Modules\Inventory\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Inventory\Models\RackImportBatch;
use Modules\Inventory\Models\RackImportRow;

class RackImportBatchRepository
{
    public function paginateBatches(): LengthAwarePaginator
    {
        return RackImportBatch::query()
            ->with('executor:id,name')
            ->when(request('state'), fn ($q, $state) => $q->where('state', $state))
            ->orderByDesc('created_at')
            ->paginate((int) request('per_page', 20))
            ->appends(request()->query());
    }

    public function find(string $id): ?RackImportBatch
    {
        return RackImportBatch::with('executor:id,name')->find($id);
    }

    public function paginateRows(RackImportBatch $batch): LengthAwarePaginator
    {
        return RackImportRow::where('batch_id', $batch->id)
            ->when(request('status'), fn ($q, $status) => $q->where('status', $status))
            ->allowedSearch('raw_sku', 'raw_bin', 'raw_location')
            ->orderBy('row_no')
            ->paginate((int) request('per_page', 20))
            ->appends(request()->query());
    }
}
