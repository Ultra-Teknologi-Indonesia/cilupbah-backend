<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Product\Http\Resources\ProductMonitorResource;
use Modules\Product\Services\ChannelMonitorService;
use OpenApi\Attributes as OA;

class ChannelMonitorController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ChannelMonitorService $monitorService,
    ) {}

    #[OA\Get(
        path: '/api/v1/channel-monitor',
        summary: 'Pantauan — list produk yang live di channel beserta status sync',
        description: 'Daftar produk yang terhubung ke setidaknya satu channel marketplace, lengkap dengan sync_status dan data SKU tersinkron per channel. Filter opsional: shop_id, channel, sync_status.',
        tags: ['Channel Monitor'],
        parameters: [
            new OA\Parameter(name: 'shop_id', in: 'query', required: false, description: 'Filter ke satu toko (shop_id marketplace)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'channel', in: 'query', required: false, description: 'Filter berdasarkan kode channel (tiktok, shopee, dll)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sync_status', in: 'query', required: false, description: 'Filter: synced | pending | failed | syncing | deactivated', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Cari nama produk (PostgreSQL FTS)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List produk berhasil'),
            new OA\Response(response: 422, description: 'sync_status tidak valid'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $validStatuses = ['synced', 'pending', 'failed', 'syncing', 'deactivated'];
        $syncStatus    = $request->query('sync_status');

        if ($syncStatus !== null && !in_array($syncStatus, $validStatuses, true)) {
            return $this->errorResponse(
                'sync_status tidak valid. Nilai yang diizinkan: ' . implode(', ', $validStatuses),
                422
            );
        }

        $products = $this->monitorService->getMonitoredProducts(
            $request->query('shop_id'),
            $request->query('channel'),
            $syncStatus
        );

        return $this->successPaginatedResponse(
            ProductMonitorResource::collection($products),
            'Pantauan produk berhasil'
        );
    }

    #[OA\Get(
        path: '/api/v1/channel-monitor/summary',
        summary: 'Ringkasan statistik sync per toko aktif',
        description: 'Statistik agregat per channel shop: total linked, synced, failed, pending, last_synced_at, failed_last_24h.',
        tags: ['Channel Monitor'],
        parameters: [
            new OA\Parameter(name: 'channel', in: 'query', required: false, description: 'Filter berdasarkan kode channel', schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Summary berhasil')]
    )]
    public function summary(Request $request): JsonResponse
    {
        $data = $this->monitorService->getShopsSummary($request->query('channel'));

        return $this->successResponse($data, 'Channel monitor summary');
    }

    #[OA\Get(
        path: '/api/v1/channel-monitor/{shop_id}',
        summary: 'Detail monitoring satu toko',
        description: 'Stats lengkap + daftar produk gagal (recent_failures) + produk pending untuk satu channel shop.',
        tags: ['Channel Monitor'],
        parameters: [
            new OA\Parameter(name: 'shop_id', in: 'path', required: true, description: 'shop_id marketplace', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detail berhasil'),
            new OA\Response(response: 404, description: 'Toko tidak ditemukan'),
        ]
    )]
    public function detail(Request $request, string $shopId): JsonResponse
    {
        $data = $this->monitorService->getShopDetail($shopId);

        if (empty($data)) {
            return $this->errorResponse('Toko tidak ditemukan', 404);
        }

        return $this->successResponse($data, 'Channel monitor detail');
    }

    #[OA\Get(
        path: '/api/v1/channel-monitor/{shop_id}/products',
        summary: 'List produk di satu toko dengan status sync & data SKU tersinkron',
        tags: ['Channel Monitor'],
        parameters: [
            new OA\Parameter(name: 'shop_id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sync_status', in: 'query', required: false, description: 'Filter: synced | pending | failed | syncing | deactivated', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List produk berhasil'),
            new OA\Response(response: 404, description: 'Toko tidak ditemukan'),
            new OA\Response(response: 422, description: 'sync_status tidak valid'),
        ]
    )]
    public function products(Request $request, string $shopId): JsonResponse
    {
        $validStatuses = ['synced', 'pending', 'failed', 'syncing', 'deactivated'];
        $syncStatus    = $request->query('sync_status');

        if ($syncStatus !== null && !in_array($syncStatus, $validStatuses, true)) {
            return $this->errorResponse(
                'sync_status tidak valid. Nilai yang diizinkan: ' . implode(', ', $validStatuses),
                422
            );
        }

        $results = $this->monitorService->getShopProducts(
            $shopId,
            $syncStatus
        );

        if ($results->isEmpty() && !$this->monitorService->getShopDetail($shopId)) {
            return $this->errorResponse('Toko tidak ditemukan', 404);
        }

        return $this->successPaginatedResponse(
            $results->through(fn ($mapping) => $this->mapShopProduct($mapping)),
            'Channel monitor products'
        );
    }

    private function mapShopProduct(object $m): array
    {
        $product      = $m->product;
        $primaryImage = $product?->media->firstWhere('is_primary', true)?->url
            ?? $product?->media->first()?->url;

        return [
            'product_id'          => $m->product_id,
            'product_name'        => $product->name ?? null,
            'sku'                 => $product->sku ?? null,
            'product_status'      => $product->status ?? null,
            'primary_image'       => $primaryImage,
            'external_product_id' => $m->external_product_id,
            'sync_status'         => $m->sync_status,
            'error_message'       => $m->error_message,
            'last_synced_at'      => $m->last_synced_at,
            'skus'                => $m->variantMappings->map(fn ($vm) => [
                'variant_id'      => $vm->variant_id,
                'sku'             => $vm->variant->sku ?? null,
                'external_sku_id' => $vm->external_sku_id,
                'sell_price'      => $vm->variant?->sell_price !== null ? (float) $vm->variant->sell_price : null,
                'override_price'  => $vm->override_price !== null ? (float) $vm->override_price : null,
                'synced_price'    => $vm->synced_price !== null ? (float) $vm->synced_price : null,
                'synced_stock'    => $vm->synced_stock,
            ])->values()->all(),
        ];
    }
}
