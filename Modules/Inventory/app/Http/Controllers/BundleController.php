<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Inventory\Http\Resources\BundleResource;
use Modules\Inventory\Services\BundleService;

class BundleController extends Controller
{
    use ApiResponse;

    public function __construct(private BundleService $bundleService) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 20);
        $paginator = $this->bundleService->list($perPage);

        $paginator->getCollection()->transform(
            fn ($product) => (new BundleResource($product))->resolve($request),
        );

        return $this->successPaginatedResponse($paginator);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'required|string|max:100',
            'category_id' => 'nullable|exists:categories,id',
            'sell_price' => 'nullable|numeric|min:0',
            'components' => 'required|array|min:1',
            'components.*.variant_id' => [
                'required',
                Rule::exists('product_variants', 'id')->where(fn ($q) => $q->where('is_active', true)),
            ],
            'components.*.qty' => 'required|integer|min:1',
        ], [
            'components.*.variant_id.exists' => 'Varian komponen tidak ditemukan atau tidak aktif.',
        ]);

        try {
            [$product, $isUpdate] = $this->bundleService->save($request->all());
        } catch (\DomainException|\RuntimeException $e) {
            return $this->errorResponse(
                'Gagal menyimpan.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse(
            new BundleResource($product),
            $isUpdate ? 'Bundle updated.' : 'Bundle created.',
            $isUpdate ? 200 : 201,
        );
    }
}
