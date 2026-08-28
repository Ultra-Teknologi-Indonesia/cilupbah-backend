<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Product\Http\Resources\ChannelAttributeResource;
use Modules\Product\Jobs\SyncChannelAttributesJob;
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
                SyncChannelAttributesJob::dispatch($channelId, $categoryId)
                    ->onQueue(config('queue.names.channel_product'));

                return $this->successResponse(null, 'Proses sinkronisasi atribut sedang berjalan di latar belakang', 202);
            }

            $attributes = $this->service->getPaginated($categoryId);

            return $this->successPaginatedResponse(ChannelAttributeResource::collection($attributes), 'Berhasil mengambil daftar atribut channel');
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function all(): JsonResponse
    {
        $attributes = $this->service->getAllAttributes();

        return $this->successPaginatedResponse(ChannelAttributeResource::collection($attributes), 'Berhasil mengambil semua atribut kategori channel');
    }
}
