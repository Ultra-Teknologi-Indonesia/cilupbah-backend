<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sales\Http\Requests\StoreInternalStoreRequest;
use Modules\Sales\Http\Requests\UpdateInternalStoreRequest;
use Modules\Sales\Http\Resources\InternalStoreResource;
use Modules\Sales\Services\InternalStoreService;

class InternalStoreController extends Controller
{
    use ApiResponse;

    public function __construct(private InternalStoreService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage   = (int) $request->query('per_page', 20);
        $paginator = $this->service->list($perPage);

        return $this->successResponse(
            InternalStoreResource::collection($paginator->items()),
            'Daftar toko internal berhasil diambil',
            200,
            [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ]
        );
    }

    public function all(): JsonResponse
    {
        $items = $this->service->all();
        return $this->successResponse(
            InternalStoreResource::collection($items),
            'Daftar toko internal aktif'
        );
    }

    public function store(StoreInternalStoreRequest $request): JsonResponse
    {
        $store = $this->service->create(
            $request->only(['name', 'is_active']),
            $request->file('logo')
        );

        return $this->successResponse(
            new InternalStoreResource($store),
            'Toko internal berhasil dibuat',
            201
        );
    }

    public function show(string $id): JsonResponse
    {
        $store = $this->service->find($id);
        if (!$store) {
            return $this->errorResponse('Toko internal tidak ditemukan', 404);
        }

        return $this->successResponse(
            new InternalStoreResource($store),
            'Detail toko internal'
        );
    }

    public function update(UpdateInternalStoreRequest $request, string $id): JsonResponse
    {
        $store = $this->service->update(
            $id,
            $request->only(['name', 'is_active']),
            $request->file('logo')
        );

        return $this->successResponse(
            new InternalStoreResource($store),
            'Toko internal berhasil diperbarui'
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return $this->successResponse(null, 'Toko internal berhasil dihapus');
    }

    public function uploadLogo(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'file', 'image', 'max:2048'],
        ]);

        $store = $this->service->replaceLogo($id, $request->file('logo'));
        return $this->successResponse(
            new InternalStoreResource($store),
            'Logo toko berhasil diunggah'
        );
    }

    public function deleteLogo(string $id): JsonResponse
    {
        $store = $this->service->deleteLogo($id);
        return $this->successResponse(
            new InternalStoreResource($store),
            'Logo toko berhasil dihapus'
        );
    }
}
