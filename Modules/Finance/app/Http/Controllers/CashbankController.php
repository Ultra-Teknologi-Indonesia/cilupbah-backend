<?php

namespace Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Finance\Http\Requests\CashbankIndexRequest;
use Modules\Finance\Services\CashbankService;
use OpenApi\Attributes as OA;

/**
 * Cash & Bank (Jubelio: getPayments/getReceives + detail).
 * Read-only view di atas pembayaran Sales/Purchase — lihat PLAN-CASHBANK.md.
 */
#[OA\Tag(name: 'Cash & Bank', description: 'Mutasi kas/bank: uang masuk (receives) & uang keluar (payments)')]
class CashbankController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected CashbankService $cashbankService,
    ) {}

    #[OA\Get(
        path: '/api/v1/cashbank/payments',
        summary: 'Daftar pembayaran kas/bank (uang keluar)',
        security: [['bearerAuth' => []]],
        tags: ['Cash & Bank'],
        parameters: [
            new OA\Parameter(name: 'transactionDateFrom', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'transactionDateTo', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'sort', in: 'query', required: false, description: 'payment_date | amount | payment_number (prefix - untuk desc)', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK'),
            new OA\Response(response: 422, description: 'Validasi gagal'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function payments(CashbankIndexRequest $request): JsonResponse
    {
        $list = $this->cashbankService->listPayments(
            $request->validated('transactionDateFrom'),
            $request->validated('transactionDateTo'),
        );

        return $this->successPaginatedResponse($list, 'Daftar pembayaran kas/bank berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/cashbank/payments/{id}',
        summary: 'Detail satu pembayaran kas/bank',
        security: [['bearerAuth' => []]],
        tags: ['Cash & Bank'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'OK'),
            new OA\Response(response: 404, description: 'Tidak ditemukan'),
        ]
    )]
    public function paymentShow(string $id): JsonResponse
    {
        $detail = $this->cashbankService->paymentDetail($id);

        if (! $detail) {
            return $this->errorResponse('Pembayaran tidak ditemukan.', 404);
        }

        return $this->successResponse($detail, 'Detail pembayaran berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/cashbank/receives',
        summary: 'Daftar penerimaan kas/bank (uang masuk)',
        security: [['bearerAuth' => []]],
        tags: ['Cash & Bank'],
        parameters: [
            new OA\Parameter(name: 'transactionDateFrom', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'transactionDateTo', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK'),
            new OA\Response(response: 422, description: 'Validasi gagal'),
        ]
    )]
    public function receives(CashbankIndexRequest $request): JsonResponse
    {
        $list = $this->cashbankService->listReceives(
            $request->validated('transactionDateFrom'),
            $request->validated('transactionDateTo'),
        );

        return $this->successPaginatedResponse($list, 'Daftar penerimaan kas/bank berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/cashbank/receives/{id}',
        summary: 'Detail satu penerimaan kas/bank',
        security: [['bearerAuth' => []]],
        tags: ['Cash & Bank'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'OK'),
            new OA\Response(response: 404, description: 'Tidak ditemukan'),
        ]
    )]
    public function receiveShow(string $id): JsonResponse
    {
        $detail = $this->cashbankService->receiveDetail($id);

        if (! $detail) {
            return $this->errorResponse('Penerimaan tidak ditemukan.', 404);
        }

        return $this->successResponse($detail, 'Detail penerimaan berhasil diambil.');
    }
}
