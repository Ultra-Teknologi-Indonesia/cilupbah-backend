<?php

namespace Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Finance\Http\Requests\JournalIndexRequest;
use Modules\Finance\Http\Requests\SaveManualJournalRequest;
use Modules\Finance\Services\JournalService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Journal', description: 'Jurnal umum & Chart of Accounts')]
class JournalController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected JournalService $journalService,
    ) {}

    #[OA\Get(
        path: '/api/v1/journal/',
        summary: 'Semua jurnal (otomatis + manual)',
        security: [['bearerAuth' => []]],
        tags: ['Journal'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, description: 'Cari journal_no / source_doc_no / notes', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'createdSince', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'sort', in: 'query', required: false, description: 'transaction_date | journal_no | created_at (prefix - desc)', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK'),
            new OA\Response(response: 422, description: 'Validasi gagal'),
        ]
    )]
    public function index(JournalIndexRequest $request): JsonResponse
    {
        $list = $this->journalService->listAll(
            $request->validated('q'),
            $request->validated('createdSince'),
        );

        return $this->successPaginatedResponse($list, 'Daftar jurnal berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/journal/manual-journal/',
        summary: 'Jurnal manual saja',
        security: [['bearerAuth' => []]],
        tags: ['Journal'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'createdSince', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [new OA\Response(response: 200, description: 'OK')]
    )]
    public function manualIndex(JournalIndexRequest $request): JsonResponse
    {
        $list = $this->journalService->listManual(
            $request->validated('q'),
            $request->validated('createdSince'),
        );

        return $this->successPaginatedResponse($list, 'Daftar jurnal manual berhasil diambil.');
    }

    #[OA\Post(
        path: '/api/v1/journal/manual-journal/',
        summary: 'Buat (journal_id=0) / ubah (journal_id=uuid) jurnal manual',
        security: [['bearerAuth' => []]],
        tags: ['Journal'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['accounts'],
                properties: [
                    new OA\Property(property: 'journal_id', description: '0/null = buat baru; uuid = ubah jurnal manual', example: 0),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
                    new OA\Property(property: 'source_doc_no', type: 'string', nullable: true),
                    new OA\Property(property: 'transaction_date', type: 'string', format: 'date-time', nullable: true),
                    new OA\Property(property: 'accounts', type: 'array', items: new OA\Items(
                        required: ['account_id', 'debit', 'credit'],
                        properties: [
                            new OA\Property(property: 'account_id', type: 'string', description: 'UUID akun dari /accounts/lookup/all'),
                            new OA\Property(property: 'debit', type: 'number', example: 100000),
                            new OA\Property(property: 'credit', type: 'number', example: 0),
                            new OA\Property(property: 'description', type: 'string', nullable: true),
                        ]
                    )),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Tersimpan'),
            new OA\Response(response: 404, description: 'Jurnal target edit tidak ditemukan'),
            new OA\Response(response: 422, description: 'Tidak seimbang / jurnal otomatis / validasi gagal'),
        ]
    )]
    public function saveManual(SaveManualJournalRequest $request): JsonResponse
    {
        try {
            $result = $this->journalService->saveManual($request->validated(), Auth::id());
        } catch (\DomainException $e) {
            return $this->errorResponse(
                'Gagal menyimpan.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        if ($result === null) {
            return $this->errorResponse('Jurnal tidak ditemukan.', 404);
        }

        return $this->successResponse($result, 'Jurnal manual berhasil disimpan.');
    }

    #[OA\Get(
        path: '/api/v1/journal/{id}',
        summary: 'Detail jurnal + baris debit/kredit',
        security: [['bearerAuth' => []]],
        tags: ['Journal'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'OK'),
            new OA\Response(response: 404, description: 'Tidak ditemukan'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $detail = $this->journalService->detail($id);

        if (! $detail) {
            return $this->errorResponse('Jurnal tidak ditemukan.', 404);
        }

        return $this->successResponse($detail, 'Detail jurnal berhasil diambil.');
    }
}
