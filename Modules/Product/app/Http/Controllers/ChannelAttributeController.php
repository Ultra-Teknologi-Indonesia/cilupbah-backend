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
            if ($request->query('sync')) {
                \Modules\Product\Jobs\SyncChannelAttributesJob::dispatch($channelId, $categoryId)
                    ->onQueue('channel_sync');
                
                return $this->successResponse(null, 'Proses sinkronisasi atribut sedang berjalan di latar belakang', 202);
            }

            $attributes = $this->service->getPaginated($categoryId);
            return $this->successPaginatedResponse(ChannelAttributeResource::collection($attributes), 'Berhasil mengambil daftar atribut channel');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
