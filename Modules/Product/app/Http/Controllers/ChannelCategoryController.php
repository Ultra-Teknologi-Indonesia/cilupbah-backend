<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Product\Http\Resources\ChannelCategoryResource;
use Modules\Product\Services\ChannelCategoryService;

class ChannelCategoryController extends Controller
{
    use ApiResponse;

    protected ChannelCategoryService $service;

    public function __construct(ChannelCategoryService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request, string $channelId): JsonResponse
    {
        if ($request->query('all')) {
            $categories = $this->service->getAll($channelId);
            return $this->successResponse(ChannelCategoryResource::collection($categories), 'Berhasil mengambil semua kategori channel');
        }

        $categories = $this->service->getPaginated($channelId);
        return $this->successPaginatedResponse(ChannelCategoryResource::collection($categories), 'Berhasil mengambil daftar kategori channel');
    }

    public function storeCategories(Request $request, string $channelId, string $storeId): JsonResponse
    {
        $categories = \Modules\Product\Models\ChannelCategory::where('channel_id', $channelId)
            ->whereHas('localCategories', function ($q) use ($storeId) {
                $q->whereHas('products', function ($pq) use ($storeId) {
                    $pq->whereHas('channelMappings', fn ($cm) => $cm->where('channel_shop_id', $storeId));
                });
            })
            ->with('localCategories:id,name')
            ->get();

        return $this->successResponse(
            ChannelCategoryResource::collection($categories),
            'Berhasil mengambil kategori toko.'
        );
    }
}
