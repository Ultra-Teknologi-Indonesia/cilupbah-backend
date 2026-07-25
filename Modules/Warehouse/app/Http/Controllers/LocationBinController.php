<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Http\Requests\AssignSkuLocationBinRequest;
use Modules\Warehouse\Http\Requests\GenerateLocationBinRequest;
use Modules\Warehouse\Http\Requests\StoreLocationBinRequest;
use Modules\Warehouse\Http\Requests\UniformApplyLocationBinRequest;
use Modules\Warehouse\Http\Resources\LocationBinResource;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Services\BinLayoutImporter;
use Modules\Warehouse\Services\BinQrPrintService;
use Modules\Warehouse\Services\LocationBinService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use OpenApi\Attributes as OA;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

#[OA\Tag(name: 'Location Bins', description: 'API Endpoints for Warehouse Location Bins')]
#[OA\Schema(
    schema: 'LocationBin',
    title: 'Location Bin Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'location_id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'floor_code', type: 'string', example: 'FL-01', nullable: true),
        new OA\Property(property: 'row_code', type: 'string', example: 'RW-02', nullable: true),
        new OA\Property(property: 'column_code', type: 'string', example: 'COL-A', nullable: true),
        new OA\Property(property: 'bin_code', type: 'string', example: 'BIN-100', nullable: true),
        new OA\Property(property: 'bin_final_code', type: 'string', example: 'FL01-RW02-COLA-BIN100', nullable: true),
        new OA\Property(property: 'is_inbound', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
class LocationBinController extends Controller
{
    public function __construct(
        protected LocationBinService $binService
    ) {}

    #[OA\Get(
        path: '/api/v1/locations/{locationId}/bins',
        summary: 'Get list of bins for a location',
        security: [['bearerAuth' => []]],
        tags: ['Location Bins'],
        parameters: [
            new OA\Parameter(name: 'locationId', in: 'path', required: true, description: 'ID of the location', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/LocationBin')),
                        new OA\Property(property: 'message', type: 'string', example: 'Daftar bin berhasil diambil'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(string $locationId): JsonResponse
    {
        try {
            $paginator = $this->binService->getByLocationPaginated($locationId);

            $paginator->setCollection(
                LocationBinResource::collection($paginator->getCollection())->collection
            );

            return $this->successPaginatedResponse($paginator, 'Daftar bin berhasil diambil');
        } catch (\Exception $e) {
            throw $e;
        }
    }

    #[OA\Get(
        path: '/api/v1/locations/{locationId}/default-bin',
        summary: 'Get default bin for a location',
        security: [['bearerAuth' => []]],
        tags: ['Location Bins'],
        parameters: [
            new OA\Parameter(name: 'locationId', in: 'path', required: true, description: 'ID of the location', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/LocationBin'),
                        new OA\Property(property: 'message', type: 'string', example: 'Default bin berhasil diambil'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Default bin tidak ditemukan.'),
        ]
    )]
    public function defaultBin(string $locationId): JsonResponse
    {
        $bin = $this->binService->getDefaultBin($locationId);

        if (! $bin) {
            return $this->errorResponse('Default bin tidak ditemukan.', 404);
        }

        return $this->successResponse($bin, 'Default bin berhasil diambil');
    }

    #[OA\Post(
        path: '/api/v1/locations/{locationId}/bins/preview',
        summary: 'Preview location bins generation',
        security: [['bearerAuth' => []]],
        tags: ['Location Bins'],
        parameters: [
            new OA\Parameter(name: 'locationId', in: 'path', required: true, description: 'ID of the location', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['floor_code', 'qty_floor', 'row_code', 'qty_row', 'column_code', 'qty_column', 'bin_code', 'qty_bin'],
                properties: [
                    new OA\Property(property: 'floor_code', type: 'string', example: 'FL'),
                    new OA\Property(property: 'qty_floor', type: 'integer', example: 1),
                    new OA\Property(property: 'row_code', type: 'string', example: 'RW'),
                    new OA\Property(property: 'qty_row', type: 'integer', example: 2),
                    new OA\Property(property: 'column_code', type: 'string', example: 'C'),
                    new OA\Property(property: 'qty_column', type: 'integer', example: 3),
                    new OA\Property(property: 'bin_code', type: 'string', example: 'B'),
                    new OA\Property(property: 'qty_bin', type: 'integer', example: 4),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Preview generated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'object'),
                        new OA\Property(property: 'message', type: 'string', example: 'Preview berhasil di-generate'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function preview(GenerateLocationBinRequest $request, string $locationId): JsonResponse
    {
        try {
            $validated = $request->validated();
            $page = (int) ($validated['page'] ?? 1);
            $perPage = (int) ($validated['per_page'] ?? 50);

            $preview = $this->binService->previewMassGenerate($validated, $page, $perPage);

            return $this->successResponse(
                $preview['data'],
                'Preview berhasil di-generate.',
                200,
                $preview['meta']
            );
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function uniformApply(UniformApplyLocationBinRequest $request, string $locationId): JsonResponse
    {
        try {
            $affected = $this->binService->uniformApply($locationId, $request->validated());

            return $this->successResponse(
                ['affected' => $affected],
                "Berhasil menerapkan ke {$affected} rak."
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memproses pemetaan.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function store(StoreLocationBinRequest $request): JsonResponse
    {
        try {
            $bin = $this->binService->create($request->validated());
        } catch (UniqueConstraintViolationException $e) {
            return $this->errorResponse('Bin dengan kode tersebut sudah ada pada lokasi ini.', 422);
        }

        return $this->successResponse($bin, 'Bin berhasil dibuat', 201);
    }

    public function destroy(string $id): JsonResponse
    {
        $bin = $this->binService->getById($id);
        if (! $bin) {
            return $this->errorResponse('Bin tidak ditemukan.', 404);
        }

        try {
            $this->binService->delete($id);
        } catch (\DomainException $e) {
            return $this->errorResponse(
                'Gagal menghapus.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse(null, 'Bin berhasil dihapus');
    }

    public function generate(GenerateLocationBinRequest $request, string $locationId): JsonResponse
    {
        try {
            $result = $this->binService->massGenerate($locationId, $request->validated());
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Lokasi tidak ditemukan.', 404);
        }

        return $this->successResponse($result, 'Bin berhasil di-generate', 201);
    }

    private const RACK_CODE_ALIASES = ['kode_rak', 'rak', 'bin_final_code', 'no rak', 'no_rak', 'kode rak', 'kode final rak'];

    private const IMPORT_SHEET_NAME = 'Pengisian Data';

    public function importTemplate(string $locationId): StreamedResponse
    {
        $location = Location::find($locationId);
        abort_if(! $location, 404, 'Lokasi tidak ditemukan.');

        $examples = LocationBin::where('location_id', $locationId)
            ->orderBy('bin_final_code')
            ->limit(3)
            ->pluck('bin_final_code')
            ->all();

        $spreadsheet = $this->buildImportTemplate($location->location_name ?? '', $examples);

        return response()->streamDownload(function () use ($spreadsheet) {
            (new XlsxWriter($spreadsheet))->save('php://output');
        }, 'template-import-kode-rak.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function buildImportTemplate(string $locationName, array $examples): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();

        $data = $spreadsheet->getActiveSheet();
        $data->setTitle(self::IMPORT_SHEET_NAME);
        $data->setCellValue('A1', 'kode_rak');
        $data->getStyle('A1')->getFont()->setBold(true);
        $data->getColumnDimension('A')->setWidth(30);
        $data->freezePane('A2');

        foreach ($examples as $i => $code) {
            $data->setCellValueExplicit(
                'A' . ($i + 2),
                $code,
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );
        }

        $instr = $spreadsheet->createSheet();
        $instr->setTitle('Instruksi');

        $instr->setCellValue('A1', 'Import Kode Rak' . ($locationName !== '' ? " — {$locationName}" : ''));
        $instr->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $lines = [
            '',
            '1. Isi kode rak di sheet "Pengisian Data", satu kode per baris, mulai baris 2.',
            '2. Jangan mengubah nama sheet maupun judul kolom "kode_rak".',
            '3. Kode rak berformat bebas. Yang dipakai sistem adalah teksnya persis.',
            '4. Kode yang sudah ada akan dilewati, bukan ditimpa. Aman dijalankan ulang.',
            '5. Baris kosong dan teks "Tidak ada rak" diabaikan.',
            '',
            'Contoh kode rak:',
            '  IN-A1-K1-P1',
            '  GK-14-K1-B1',
            '  O-LX-KX-KANTOR',
            '',
            $examples === []
                ? 'Lokasi ini belum punya rak, jadi sheet "Pengisian Data" masih kosong.'
                : 'Sheet "Pengisian Data" sudah berisi beberapa kode rak yang ADA di lokasi ini sebagai',
        ];

        if ($examples !== []) {
            $lines[] = 'contoh format. Boleh dihapus atau dibiarkan — kode yang sudah ada tidak akan diproses ulang.';
        }

        $lines[] = '';
        $lines[] = 'Zona dibuat otomatis dari segmen pertama kode rak (mis. "GK-14-K1-B1" → zona "GK").';

        foreach ($lines as $i => $line) {
            $instr->setCellValue('A' . ($i + 2), $line);
        }
        $instr->getColumnDimension('A')->setWidth(100);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    public function importPreview(Request $request, string $locationId): JsonResponse
    {
        $request->validate(['file' => 'required|file|max:20480']);

        $location = Location::find($locationId);
        if (! $location) {
            return $this->errorResponse('Lokasi tidak ditemukan.', 404);
        }

        try {
            $codes = $this->parseRackCodes($request->file('file')->getRealPath());
        } catch (Throwable $e) {
            return $this->errorResponse('Gagal membaca file.', 422, ['detail' => $e->getMessage()], 'Aksi tidak dapat diproses');
        }

        if (empty($codes)) {
            return $this->errorResponse('Tidak ada kode rak terbaca. Pastikan ada kolom "kode_rak"/"rak"/"No Rak".', 422);
        }

        $result = app(BinLayoutImporter::class)->import($location, $codes, false);

        return $this->successResponse([
            'total' => $result['total'],
            'new' => count($result['new_codes']),
            'existing' => $result['existing'],
            'sample_new' => array_slice($result['new_codes'], 0, 50),
        ], 'Pratinjau import rak berhasil.');
    }

    public function import(Request $request, string $locationId): JsonResponse
    {
        $request->validate(['file' => 'required|file|max:20480']);

        $location = Location::find($locationId);
        if (! $location) {
            return $this->errorResponse('Lokasi tidak ditemukan.', 404);
        }

        try {
            $codes = $this->parseRackCodes($request->file('file')->getRealPath());
        } catch (Throwable $e) {
            return $this->errorResponse('Gagal membaca file.', 422, ['detail' => $e->getMessage()], 'Aksi tidak dapat diproses');
        }

        if (empty($codes)) {
            return $this->errorResponse('Tidak ada kode rak terbaca.', 422);
        }

        $result = app(BinLayoutImporter::class)->import($location, $codes, true);

        return $this->successResponse([
            'created' => $result['created'],
            'existing' => $result['existing'],
            'zones_created' => $result['zones_created'],
        ], "Import rak selesai. {$result['created']} rak baru dibuat, {$result['existing']} sudah ada.");
    }

    private function parseRackCodes(string $path): array
    {
        $spreadsheet = IOFactory::load($path);

        $sheet = $spreadsheet->getSheetByName(self::IMPORT_SHEET_NAME) ?? $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, false, false);
        if (empty($rows)) {
            return [];
        }

        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $rows[0]);
        $idx = null;
        foreach ($header as $i => $name) {
            if (in_array($name, self::RACK_CODE_ALIASES, true)) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            $idx = count($header) === 1 ? 0 : count($header) - 1;
        }

        $codes = [];
        foreach (array_slice($rows, 1) as $row) {
            $v = trim((string) ($row[$idx] ?? ''));
            if ($v !== '' && mb_strtolower($v) !== 'tidak ada rak') {
                $codes[] = $v;
            }
        }

        return array_values(array_unique($codes));
    }

    public function bulkUpdate(Request $request, string $locationId): JsonResponse
    {
        $validated = $request->validate([
            'bins' => 'required|array|min:1',
            'bins.*.id' => 'required|uuid',
            'bins.*.bin_final_code' => 'required|string|max:255',
            'bins.*.is_stock_acknowledged' => 'required|boolean',
            'bins.*.is_large_bin' => 'required|boolean',
            'bins.*.category' => 'nullable|string|max:255',
        ]);

        try {
            $updated = $this->binService->bulkUpdate($locationId, $validated['bins']);

            return $this->successResponse(['updated' => $updated], 'Rak berhasil diperbarui.');
        } catch (UniqueConstraintViolationException $e) {
            return $this->errorResponse('Kode rak harus unik dalam satu lokasi. Ada kode yang duplikat.', 422);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memperbarui.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function pendingPutawaySkus(Request $request, string $locationId): JsonResponse
    {
        $search = $request->query('search');
        $items = $this->binService->getPendingPutawaySkus(
            $locationId,
            is_string($search) ? $search : null,
        );

        return $this->successResponse($items, 'Daftar SKU siap putaway berhasil diambil');
    }

    public function assignSku(AssignSkuLocationBinRequest $request, string $locationId, string $binId): JsonResponse
    {
        try {
            $result = $this->binService->assignSkuToBin(
                $locationId,
                $binId,
                $request->validated()['item_id'],
                (string) ($request->user()?->id ?? ''),
            );

            return $this->successResponse($result, 'SKU berhasil ditempatkan ke rak.');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Lokasi atau rak tidak ditemukan.', 404);
        } catch (\DomainException $e) {
            return $this->errorResponse(
                'Gagal menempatkan SKU.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public const PAPER_THERMAL_50X40 = 'thermal_50x40';

    public const PAPER_THERMAL_80X40 = 'thermal_80x40';

    public const PAPER_A4_SINGLE = 'a4_single';

    public const PAPER_A4_MULTI = 'a4_multi';

    public const MAX_BINS_PER_REQUEST = 500;

    #[OA\Get(
        path: '/api/v1/locations/{locationId}/bins/print-qr',
        summary: 'Cetak label QR per bin sebagai PDF (default: thermal 50x40mm)',
        security: [['bearerAuth' => []]],
        tags: ['Location Bins'],
        parameters: [
            new OA\Parameter(name: 'locationId', in: 'path', required: true, description: 'ID lokasi', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'bin_ids', in: 'query', required: false, description: 'CSV daftar UUID bin (opsional, default semua bin di lokasi)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'paper', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['thermal_50x40', 'thermal_80x40', 'a4_single', 'a4_multi'], default: 'thermal_50x40')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'PDF stream', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(response: 404, description: 'Lokasi tidak ditemukan'),
            new OA\Response(response: 422, description: 'Validation Error / batas bin terlampaui'),
        ]
    )]
    public function printQr(Request $request, string $locationId)
    {
        $validated = $request->validate([
            'bin_ids' => 'nullable|string',
            'paper' => 'nullable|string|in:thermal_50x40,thermal_80x40,a4_single,a4_multi',
        ]);

        $location = Location::find($locationId);
        if (! $location) {
            return $this->errorResponse('Lokasi tidak ditemukan.', 404);
        }

        $paper = $validated['paper'] ?? self::PAPER_THERMAL_50X40;

        $binIds = [];
        if (! empty($validated['bin_ids'])) {
            $binIds = collect(explode(',', (string) $validated['bin_ids']))
                ->map(fn ($id) => trim($id))
                ->filter()
                ->unique()
                ->values()
                ->all();

            foreach ($binIds as $id) {
                if (! preg_match('/^[0-9a-f\-]{32,36}$/i', $id)) {
                    return $this->errorResponse("ID bin tidak valid: {$id}", 422);
                }
            }
        }

        $query = app(\Modules\Warehouse\Services\BinQrPrintService::class)
            ->binsQueryForPrint($locationId, $binIds ?: null);

        $totalBins = (clone $query)->count();
        if ($totalBins > self::MAX_BINS_PER_REQUEST) {
            return $this->errorResponse(
                'Jumlah bin melebihi batas ('.self::MAX_BINS_PER_REQUEST.') untuk endpoint sinkron. '
                .'Gunakan POST /locations/{id}/bins/print-qr-job (async + progress polling) untuk bulk besar.',
                422
            );
        }

        $bins = $query->orderBy('bin_final_code')->get();

        try {
            $qrSize = $this->qrSizeFor($paper);
            $items = $bins->map(function (LocationBin $bin) use ($qrSize) {
                return [
                    'bin_final_code' => (string) $bin->bin_final_code,
                    'qr_data_uri' => $this->generateQrDataUri((string) $bin->bin_final_code, $qrSize),
                ];
            })->all();

            $view = Pdf::loadView('warehouse::pdf.bin-qr', [
                'location' => $location,
                'items' => $items,
                'paper' => $paper,
            ]);

            $this->applyPaperSettings($view, $paper);

            $filename = sprintf(
                'bin-qr-%s-%s.pdf',
                $location->location_code ?: 'LOC',
                now()->format('YmdHis')
            );

            return $view->stream($filename);
        } catch (Throwable $e) {
            report($e);

            return $this->errorResponse(
                'Gagal membuat PDF QR bin.',
                500,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function printQrJob(Request $request, string $locationId)
    {
        $validated = $request->validate([
            'bin_ids' => 'nullable|string',
            'paper' => 'nullable|string|in:thermal_50x40,thermal_80x40,a4_single,a4_multi',
        ]);

        $opts = [
            'paper' => $validated['paper'] ?? self::PAPER_THERMAL_50X40,
            'user_id' => auth()->id(),
        ];

        if (! empty($validated['bin_ids'])) {
            $opts['bin_ids'] = array_map('trim', explode(',', (string) $validated['bin_ids']));
        }

        try {

            $service = app(\Modules\Warehouse\Services\BinQrPrintService::class);
            $job = $service->createJob($locationId, $opts);
        } catch (\Throwable $e) {
            report($e);
            return $this->errorResponse(
                'Gagal membuat job print QR.',
                500,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse([
            'job_id' => $job->id,
            'status' => $job->status,
        ], 'Job print QR rak berhasil dibuat.');
    }

    protected function applyPaperSettings($pdf, string $paper): void
    {
        switch ($paper) {
            case self::PAPER_THERMAL_50X40:
                $pdf->setPaper([0, 0, 141.7, 113.4], 'portrait');
                break;
            case self::PAPER_THERMAL_80X40:
                $pdf->setPaper([0, 0, 226.8, 113.4], 'portrait');
                break;
            case self::PAPER_A4_SINGLE:
            case self::PAPER_A4_MULTI:
            default:
                $pdf->setPaper('a4', 'portrait');
                break;
        }
    }

    protected function qrSizeFor(string $paper): int
    {
        return match ($paper) {
            self::PAPER_THERMAL_50X40 => 200,
            self::PAPER_THERMAL_80X40 => 220,
            self::PAPER_A4_SINGLE => 600,
            self::PAPER_A4_MULTI => 220,
            default => 200,
        };
    }

    protected function generateQrDataUri(string $content, int $size): ?string
    {
        try {
            $svg = QrCode::format('svg')
                ->size($size)
                ->margin(0)
                ->errorCorrection('M')
                ->generate($content);

            return 'data:image/svg+xml;base64,'.base64_encode((string) $svg);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
