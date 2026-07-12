<?php

namespace Modules\Supplier\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Models\ImpexActivity;
use Modules\Inventory\Services\ImpexActivityService;
use Modules\Supplier\Services\ContactImportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ContactImportController extends Controller
{
    public function __construct(
        protected ContactImportService $importService,
        protected ImpexActivityService $activityService,
    ) {}

    public function downloadTemplate(): BinaryFileResponse
    {
        $path = $this->importService->generateTemplate();

        return response()->download($path, 'template-import-kontak.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    public function validate(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:5120',
        ]);

        try {
            $result = $this->importService->validateFile($request->file('file'));

            return $this->successResponse($result, 'Validasi selesai');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memproses file.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function save(Request $request): JsonResponse
    {
        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.mapped' => 'required|array',
            'rows.*.mapped.name' => 'required|string',
            'rows.*.mapped.type' => 'required|string|in:CUSTOMER,SUPPLIER,BOTH',
        ]);

        $activity = $this->activityService->record(
            ImpexActivity::DIRECTION_IMPORT,
            'Import Kontak',
            $request->user()?->id,
        );

        try {
            $count = $this->importService->saveRows($request->input('rows'));
            $this->activityService->markSuccess($activity);

            return $this->successResponse(
                ['created' => $count],
                "{$count} kontak berhasil diimport"
            );
        } catch (\Exception $e) {
            $this->activityService->markFailed($activity, $e->getMessage());

            return $this->errorResponse(
                'Gagal menyimpan.',
                500,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }
}
