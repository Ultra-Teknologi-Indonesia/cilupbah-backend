<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Inventory\Exports\RackAllocationExport;
use Modules\Inventory\Http\Resources\InventorySettingProductResource;
use Modules\Inventory\Models\ImpexActivity;
use Modules\Inventory\Services\ImpexActivityService;
use Modules\Inventory\Services\InventorySettingImportService;
use Modules\Inventory\Services\InventorySettingService;
use Modules\Report\Services\ExportManager;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventorySettingController extends Controller
{
    public function __construct(
        protected InventorySettingService $service,
        protected InventorySettingImportService $importService,
        protected ImpexActivityService $activityService,
        protected ExportManager $exportManager,
    ) {}

    public function products(Request $request): JsonResponse
    {
        $perPage = (int) ($request->query('per_page') ?? 20);
        $data = $this->service->products(
            ['search' => $request->query('search')],
            $perPage,
        );

        return $this->successPaginatedResponse(
            InventorySettingProductResource::collection($data),
            'Daftar produk berhasil diambil.',
        );
    }

    public function updateThresholds(Request $request, string $itemId): JsonResponse
    {
        $validated = $request->validate([
            'min_stock'  => 'nullable|integer|min:0',
            'safe_stock' => 'nullable|integer|min:0',
        ]);

        $variant = $this->service->updateThresholds($itemId, $validated);

        return $this->successResponse([
            'item_id'    => $variant->id,
            'min_stock'  => (int) $variant->min_stock,
            'safe_stock' => (int) $variant->safe_stock,
        ], 'Batas stok berhasil diperbarui.');
    }

    public function exportRackAllocation(Request $request)
    {
        $locationId = $request->query('location_id');
        $search = $request->query('search');

        $filename = 'alokasi-rak-' . now()->format('Ymd-His') . '.xlsx';

        $this->activityService->recordCompleted(
            ImpexActivity::DIRECTION_EXPORT,
            'Export Alokasi Rak',
            $request->user()?->id,
        );

        return Excel::download(
            new RackAllocationExport(
                is_string($locationId) ? $locationId : null,
                is_string($search) ? $search : null,
            ),
            $filename,
        );
    }

    public function exportRackAllocationAsync(Request $request): JsonResponse
    {
        $job = $this->exportManager->queue($request->user(), 'rack-allocation', [
            'location_id' => is_string($request->query('location_id')) ? $request->query('location_id') : null,
            'search' => is_string($request->query('search')) ? $request->query('search') : null,
        ]);

        return $this->successResponse(
            ['export_id' => $job->id, 'status' => $job->status],
            'Export alokasi rak sedang diproses.',
            202,
        );
    }

    public function importTemplate(string $type): StreamedResponse
    {
        $spreadsheet = $this->importService->generateTemplate($type);
        $filename = "template-import-{$type}.xlsx";

        return response()->streamDownload(function () use ($spreadsheet) {
            (new XlsxWriter($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function importPreview(Request $request, string $type): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
        ]);

        try {
            $result = $this->importService->preview($request->file('file'), $type);

            return $this->successResponse($result, 'Pratinjau import berhasil dibuat.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memproses file.',
                422,
                ['detail' => $e->getMessage()],
                'File tidak dapat diproses',
            );
        }
    }

    public function importConfirm(Request $request, string $type): JsonResponse
    {
        $request->validate([
            'preview_token' => 'required|string',
        ]);

        $label = match ($type) {
            InventorySettingImportService::TYPE_SAFE_STOCK => 'Import Batas Stok Aman',
            InventorySettingImportService::TYPE_MIN_STOCK  => 'Import Batas Stok Menipis',
            InventorySettingImportService::TYPE_RACK       => 'Import Alokasi Rak',
            default                                        => 'Import Persediaan',
        };

        $activity = $this->activityService->record(
            ImpexActivity::DIRECTION_IMPORT,
            $label,
            $request->user()?->id,
        );

        try {
            $result = $this->importService->confirm(
                $request->input('preview_token'),
                $request->user()?->id,
            );

            $this->activityService->markSuccess($activity);

            return $this->successResponse($result, 'Import berhasil diterapkan.');
        } catch (\DomainException $e) {
            $this->activityService->markFailed($activity, $e->getMessage());

            return $this->errorResponse('Gagal menerapkan import.', 422, ['detail' => $e->getMessage()], 'Aksi tidak dapat diproses');
        } catch (\Exception $e) {
            $this->activityService->markFailed($activity, $e->getMessage());

            return $this->errorResponse('Gagal menerapkan import.', 500, ['detail' => $e->getMessage()], 'Terjadi kesalahan');
        }
    }
}
