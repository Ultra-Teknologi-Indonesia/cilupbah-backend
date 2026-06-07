<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Product\Http\Resources\ChannelAttributeResource;
use Modules\Product\Services\ChannelAttributeService;

class ChannelAttributeController extends Controller
{
    use ApiResponse;

    protected ChannelAttributeService $service;

    public function __construct(ChannelAttributeService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request, string $channelId, string $categoryId): JsonResponse
    {
        try {
            // Option to force sync from TikTok
            if ($request->query('sync')) {
                $this->service->syncAttributesFromChannel($channelId, $categoryId);
            }

            $attributes = $this->service->getPaginated($categoryId);
            return $this->successPaginatedResponse(ChannelAttributeResource::collection($attributes), 'Berhasil mengambil daftar atribut channel');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
