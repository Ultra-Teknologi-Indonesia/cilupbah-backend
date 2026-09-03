<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Finance\Http\Resources\AccountLookupResource;
use Modules\Finance\Services\AccountService;
use Modules\Tax\Http\Resources\TaxLookupResource;
use Modules\Tax\Services\TaxService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Products')]
class ProductMasterDataController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AccountService $accountService,
        private readonly TaxService $taxService,
    ) {}

    private function taxList(): JsonResponse
    {
        return $this->successResponse(
            TaxLookupResource::collection($this->taxService->activeLookup())->resolve(),
            'Daftar pajak berhasil diambil'
        );
    }

    private function accountList(string $type): JsonResponse
    {
        return $this->successResponse(
            AccountLookupResource::collection($this->accountService->activeLookup($type))->resolve(),
            'Daftar akun berhasil diambil'
        );
    }

    #[OA\Get(path: '/api/v1/products/master-data/sales-taxes', summary: 'Pajak Penjualan', tags: ['Products'])]
    public function salesTaxes(): JsonResponse
    {
        return $this->taxList();
    }

    #[OA\Get(path: '/api/v1/products/master-data/purchase-taxes', summary: 'Pajak Pembelian', tags: ['Products'])]
    public function purchaseTaxes(): JsonResponse
    {
        return $this->taxList();
    }

    #[OA\Get(path: '/api/v1/products/master-data/sales-accounts', summary: 'Akun Penjualan (revenue)', tags: ['Products'])]
    public function salesAccounts(): JsonResponse
    {
        return $this->accountList('revenue');
    }

    #[OA\Get(path: '/api/v1/products/master-data/sales-return-accounts', summary: 'Retur Penjualan (revenue)', tags: ['Products'])]
    public function salesReturnAccounts(): JsonResponse
    {
        return $this->accountList('revenue');
    }

    #[OA\Get(path: '/api/v1/products/master-data/inventory-accounts', summary: 'Akun Persediaan (asset)', tags: ['Products'])]
    public function inventoryAccounts(): JsonResponse
    {
        return $this->accountList('asset');
    }

    #[OA\Get(path: '/api/v1/products/master-data/cogs-accounts', summary: 'Akun HPP (expense)', tags: ['Products'])]
    public function cogsAccounts(): JsonResponse
    {
        return $this->accountList('expense');
    }
}
