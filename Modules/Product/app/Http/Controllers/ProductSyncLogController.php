<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Product\Http\Resources\ProductSyncLogResource;
use Modules\Product\Models\ProductSyncLog;
use Modules\Channel\Models\ChannelShop;
use OpenApi\Attributes as OA;
use App\Traits\ApiResponse;

class ProductSyncLogController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/upload-histories',
        summary: 'Riwayat upload produk ke channel',
        tags: ['Product Histories'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['success', 'failed', 'pending'])),
            new OA\Parameter(name: 'channel', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'shop_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Success')]
    )]
    public function uploadHistories(Request $request): JsonResponse
    {
        return $this->histories($request, ProductSyncLog::ACTION_UPLOAD, 'Get upload histories success');
    }

    #[OA\Get(
        path: '/api/v1/download-histories',
        summary: 'Riwayat download produk dari channel',
        tags: ['Product Histories'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['success', 'failed', 'pending'])),
            new OA\Parameter(name: 'channel', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'shop_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [new OA\Response(response: 200, description: 'Success')]
    )]
    public function downloadHistories(Request $request): JsonResponse
    {
        return $this->histories($request, ProductSyncLog::ACTION_DOWNLOAD, 'Get download histories success');
    }

    /**
     * Query log tersaring untuk satu action (upload/download).
     */
    private function histories(Request $request, string $action, string $message): JsonResponse
    {
        $query = ProductSyncLog::query()
            ->with(['product:id,name,sku', 'channelShop.channel'])
            ->where('action', $action);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('shop_id')) {
            $channelShopId = ChannelShop::where('shop_id', $request->query('shop_id'))->value('id');
            // Jika shop tidak ditemukan, paksa hasil kosong.
            $query->where('channel_shop_id', $channelShopId ?: '00000000-0000-0000-0000-000000000000');
        }

        if ($request->filled('channel')) {
            $channel = $request->query('channel');
            $query->whereHas('channelShop.channel', fn ($q) => $q->where('code', $channel));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('sku', 'ilike', "%{$search}%");
            });
        }

        $logs = $query->orderByDesc('created_at')->paginate($request->input('limit', 10));

        return $this->successPaginatedResponse(ProductSyncLogResource::collection($logs), $message);
    }
}
