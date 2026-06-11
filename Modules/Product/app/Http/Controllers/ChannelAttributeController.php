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
                    ->onQueue(config('queue.names.channel_sync'));

                return $this->successResponse(null, 'Proses sinkronisasi atribut sedang berjalan di latar belakang', 202);
            }

            $attributes = $this->service->getPaginated($categoryId);
            return $this->successPaginatedResponse(ChannelAttributeResource::collection($attributes), 'Berhasil mengambil daftar atribut channel');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function globalIndex(Request $request): JsonResponse
    {
        $query = \Modules\Product\Models\ChannelAttribute::with(['options', 'channelCategory:id,name,channel_id'])
            ->when($request->channel_id, fn ($q, $v) => $q->whereHas('channelCategory', fn ($cq) => $cq->where('channel_id', $v)))
            ->when($request->search, fn ($q, $v) => $q->where('name', 'ilike', "%{$v}%"))
            ->orderBy('name');

        return $this->successPaginatedResponse(
            ChannelAttributeResource::collection($query->paginate($request->input('limit', 20))),
            'Berhasil mengambil semua atribut channel.'
        );
    }
}
