<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Inventory\Http\Requests\OrderRecoveryBatchSyncRequest;
use Modules\Inventory\Http\Requests\OrderRecoveryImportRequest;
use Modules\Inventory\Http\Requests\OrderRecoverySyncRequest;
use Modules\Inventory\Http\Resources\OrderRecoveryResultResource;
use Modules\Inventory\Imports\OrderRecoveryReferencesImport;
use Modules\Inventory\Services\OrderRecoveryService;
use Throwable;

final class OrderRecoveryController extends Controller
{
    use ApiResponse;

    public function sync(OrderRecoverySyncRequest $request, OrderRecoveryService $service): JsonResponse
    {
        $result = $service->sync(
            $request->user(),
            (array) $request->validated('items'),
            (string) $request->validated('action'),
        );

        return $this->successResponse(
            (new OrderRecoveryResultResource($result))->resolve($request),
            'Pemulihan sinkron selesai.',
        );
    }

    public function import(OrderRecoveryImportRequest $request, OrderRecoveryService $service): JsonResponse
    {
        $import = new OrderRecoveryReferencesImport(
            $request->validated('channel'),
            $request->validated('shop_id'),
        );
        try {
            Excel::import($import, $request->file('file'));
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'file' => ['File tidak dapat dibaca. Gunakan XLSX, XLS, atau CSV dengan header yang sesuai.'],
            ]);
        }

        if ($import->overflowed()) {
            throw ValidationException::withMessages([
                'file' => ['Maksimum 20 nomor pesanan unik per proses sinkron. Pecah file lalu coba kembali.'],
            ]);
        }
        if ($import->items() === []) {
            throw ValidationException::withMessages([
                'file' => ['Kolom nomor_pesanan, no_pesanan, order_no, order_id, atau reference tidak ditemukan.'],
            ]);
        }

        $result = $service->sync(
            $request->user(),
            $import->items(),
            (string) $request->validated('action'),
        );

        return $this->successResponse(
            (new OrderRecoveryResultResource($result))->resolve($request),
            'File diproses secara sinkron.',
        );
    }

    public function syncBatch(OrderRecoveryBatchSyncRequest $request, string $batch, OrderRecoveryService $service): JsonResponse
    {
        $result = $service->syncBatch($request->user(), $batch);

        return $this->successResponse(
            (new OrderRecoveryResultResource($result))->resolve($request),
            $result['has_more']
                ? 'Sebagian batch selesai disinkronkan. Lanjutkan untuk memproses sisa batch.'
                : 'Batch selesai disinkronkan.',
        );
    }
}
