<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Product\Http\Resources\AttributeResource;
use Modules\Product\Services\AttributeService;

class AttributeController extends Controller
{
    use ApiResponse;

    protected AttributeService $attributeService;

    public function __construct(AttributeService $attributeService)
    {
        $this->attributeService = $attributeService;
    }

    public function index(Request $request): JsonResponse
    {
        if ($request->query('all')) {
            $attributes = $this->attributeService->getAllAttributes();
            return $this->successResponse(AttributeResource::collection($attributes), 'Berhasil mengambil semua atribut');
        }

        $attributes = $this->attributeService->getPaginatedAttributes();
        return $this->successPaginatedResponse(AttributeResource::collection($attributes), 'Berhasil mengambil daftar atribut');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:sales,spec',
        ]);

        try {
            $attribute = $this->attributeService->createAttribute($validated);
            return $this->successResponse(new AttributeResource($attribute), 'Atribut berhasil dibuat', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $attribute = $this->attributeService->getAttributeById($id);
            return $this->successResponse(new AttributeResource($attribute), 'Berhasil mengambil detail atribut');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 404);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|in:sales,spec',
        ]);

        try {
            $attribute = $this->attributeService->updateAttribute($id, $validated);
            return $this->successResponse(new AttributeResource($attribute), 'Atribut berhasil diperbarui');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->attributeService->deleteAttribute($id);
            return $this->successResponse(null, 'Atribut berhasil dihapus');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    public function mapChannel(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'channel_attribute_ids' => 'required|array',
            'channel_attribute_ids.*' => 'required|uuid|exists:channel_attributes,id',
        ]);

        try {
            $attribute = $this->attributeService->mapToChannel($id, $validated['channel_attribute_ids']);
            return $this->successResponse(new AttributeResource($attribute), 'Berhasil memetakan atribut ke channel');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function mapOptionChannel(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'channel_attribute_option_ids' => 'required|array',
            'channel_attribute_option_ids.*' => 'required|uuid|exists:channel_attribute_options,id',
        ]);

        try {
            $option = $this->attributeService->mapOptionToChannel($id, $validated['channel_attribute_option_ids']);
            return $this->successResponse($option, 'Berhasil memetakan opsi atribut ke channel');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
