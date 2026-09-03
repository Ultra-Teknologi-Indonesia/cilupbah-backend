<?php

namespace Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Finance\Http\Resources\AccountLookupResource;
use Modules\Finance\Services\AccountService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Journal', description: 'Jurnal umum & Chart of Accounts')]
class AccountLookupController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AccountService $accountService,
    ) {}

    #[OA\Get(
        path: '/api/v1/accounts/lookup/all',
        summary: 'Daftar akun Chart of Accounts (untuk dropdown)',
        security: [['bearerAuth' => []]],
        tags: ['Journal'],
        responses: [
            new OA\Response(response: 200, description: 'OK'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function all(Request $request): JsonResponse
    {

        $type = $request->query('type');

        $data = AccountLookupResource::collection($this->accountService->activeLookup($type));

        return $this->successResponse($data, 'Daftar akun berhasil diambil.');
    }
}
