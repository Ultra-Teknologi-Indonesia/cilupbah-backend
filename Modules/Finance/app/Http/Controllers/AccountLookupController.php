<?php

namespace Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Finance\Models\Account;
use Modules\Finance\Repositories\AccountRepository;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Journal', description: 'Jurnal umum & Chart of Accounts')]
class AccountLookupController extends Controller
{
    use ApiResponse;

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
    public function all(AccountRepository $accounts): JsonResponse
    {
        $data = $accounts->getActiveLookup()->map(fn (Account $a) => [
            'account_id' => $a->id,
            'account_code' => $a->account_code,
            'account_name' => $a->display_name, // format Jubelio: "1-1000 - Kas"
        ]);

        return $this->successResponse($data, 'Daftar akun berhasil diambil.');
    }
}
