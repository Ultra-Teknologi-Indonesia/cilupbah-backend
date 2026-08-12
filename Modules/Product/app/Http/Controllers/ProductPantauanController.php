<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Product\Http\Resources\ProductPantauanResource;
use Modules\Product\Services\ProductPantauanService;

class ProductPantauanController extends Controller
{
    use ApiResponse;

    public function __construct(private ProductPantauanService $service) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lens' => 'nullable|in:belum_upload,harga,sku,atribut,gagal_upload,persyaratan,direview,ditolak',
            'search' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:200',
            'page' => 'nullable|integer|min:1',
        ]);

        $paginator = $this->service->paginate($validated['lens'] ?? 'belum_upload');

        $paginator->setCollection(
            collect(ProductPantauanResource::collectionWithRequirements(
                $paginator->getCollection(),
                $request,
            ))
        );

        return $this->successPaginatedResponse($paginator, 'Get pantauan produk success');
    }
}
