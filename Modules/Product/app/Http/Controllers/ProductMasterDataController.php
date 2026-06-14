<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Finance\Http\Resources\AccountLookupResource;
use Modules\Finance\Repositories\AccountRepository;
use Modules\Tax\Http\Resources\TaxLookupResource;
use Modules\Tax\Repositories\TaxRepository;
use OpenApi\Attributes as OA;

/**
 * Master data untuk form Buat Produk (6 dropdown):
 * Pajak Penjualan, Pajak Pembelian, Akun Penjualan, Retur Penjualan,
 * Akun Persediaan, Akun HPP. Sumber: modul Tax & Finance (akun by type).
 */
#[OA\Tag(name: 'Products')]
class ProductMasterDataController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly TaxRepository $taxes,
    ) {}

    private function taxList(): JsonResponse
    {
        return $this->successResponse(
            TaxLookupResource::collection($this->taxes->getActiveLookup())->resolve(),
            'Daftar pajak berhasil diambil'
        );
    }

    private function accountList(string $type): JsonResponse
    {
        return $this->successResponse(
            AccountLookupResource::collection($this->accounts->getActiveLookup($type))->resolve(),
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
