<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Modules\Warehouse\Services\BinQrPrintService;

class QrPrintJobController extends Controller
{
    public function __construct(protected BinQrPrintService $service) {}

    public function show(string $id): JsonResponse
    {
        try {
            $payload = $this->service->getJobStatus($id);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('QR job tidak ditemukan.', 404);
        }

        return $this->successResponse($payload, 'Status QR job.');
    }

    public function download(string $id)
    {
        try {
            return $this->service->downloadJobPdf($id);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('QR job tidak ditemukan.', 404);
        }
    }
}
