<?php

declare(strict_types=1);

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Product\Http\Requests\ProductCatalogExportRequest;
use Modules\Report\Services\ExportManager;

final class ProductCatalogExportController extends Controller
{
    use ApiResponse;

    public function store(ProductCatalogExportRequest $request, ExportManager $exports): JsonResponse
    {
        $job = $exports->queue(
            $request->user(),
            'product-catalog-csv',
            $request->normalized(),
        );

        return $this->successResponse(
            ['export_id' => $job->id, 'status' => $job->status],
            null,
            202,
        );
    }
}
