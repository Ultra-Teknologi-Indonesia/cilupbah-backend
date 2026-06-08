<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\ProductChannelMapping;
use OpenApi\Attributes as OA;

class ChannelMonitorController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/channel-monitor',
        summary: 'Ringkasan status sync semua toko (Pantauan)',
        description: 'Mengembalikan statistik sinkronisasi per channel shop: total linked, synced, failed, pending, dll.',
        tags: ['Channel Monitor'],
        parameters: [
            new OA\Parameter(name: 'channel', in: 'query', required: false, description: 'Filter berdasarkan kode channel (tiktok, shopee, dll)', schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Summary berhasil')]
    )]
    public function summary(Request $request): JsonResponse
    {
        $shops = ChannelShop::with('channel')
            ->when(
                $request->filled('channel'),
                fn ($q) => $q->whereHas('channel', fn ($q2) => $q2->where('code', $request->query('channel')))
            )
            ->where('is_active', true)
            ->get();

        $data = $shops->map(fn ($shop) => $this->buildShopSummary($shop));

        return $this->successResponse($data, 'Channel monitor summary');
    }

    #[OA\Get(
        path: '/api/v1/channel-monitor/{shop_id}',
        summary: 'Detail monitoring satu toko',
        description: 'Stats lengkap + daftar produk gagal (recent_failures) + produk pending untuk satu channel shop.',
        tags: ['Channel Monitor'],
        parameters: [
            new OA\Parameter(name: 'shop_id', in: 'path', required: true, description: 'shop_id marketplace (bukan UUID)', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detail berhasil'),
            new OA\Response(response: 404, description: 'Toko tidak ditemukan'),
        ]
    )]
    public function detail(Request $request, string $shopId): JsonResponse
    {
        $shop = ChannelShop::with('channel')->where('shop_id', $shopId)->first();

        if (!$shop) {
            return $this->errorResponse('Toko tidak ditemukan', 404);
        }

        $summary = $this->buildShopSummary($shop);

        $recentFailures = ProductChannelMapping::with('product:id,name,sku')
            ->where('channel_shop_id', $shop->id)
            ->where('sync_status', 'failed')
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->map(fn ($m) => [
                'product_id'          => $m->product_id,
                'product_name'        => $m->product->name ?? null,
                'sku'                 => $m->product->sku ?? null,
                'external_product_id' => $m->external_product_id,
                'error_message'       => $m->error_message,
                'failed_at'           => $m->updated_at,
            ]);

        $pendingProducts = ProductChannelMapping::with('product:id,name,sku')
            ->where('channel_shop_id', $shop->id)
            ->where('sync_status', 'pending')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($m) => [
                'product_id'   => $m->product_id,
                'product_name' => $m->product->name ?? null,
                'sku'          => $m->product->sku ?? null,
                'queued_at'    => $m->created_at,
            ]);

        return $this->successResponse(
            array_merge($summary, [
                'recent_failures'  => $recentFailures,
                'pending_products' => $pendingProducts,
            ]),
            'Channel monitor detail'
        );
    }

    #[OA\Get(
        path: '/api/v1/channel-monitor/{shop_id}/products',
        summary: 'List produk yang ter-link ke satu toko beserta status sync & harga/stok tersinkron',
        description: 'Menampilkan setiap produk yang terhubung ke toko dengan data PVCM (synced_price, synced_stock, override_price per SKU). Bisa difilter per sync_status.',
        tags: ['Channel Monitor'],
        parameters: [
            new OA\Parameter(name: 'shop_id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sync_status', in: 'query', required: false, description: 'Filter: synced | pending | failed | syncing | deactivated', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Cari nama produk / SKU', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List produk berhasil'),
            new OA\Response(response: 404, description: 'Toko tidak ditemukan'),
            new OA\Response(response: 422, description: 'sync_status tidak valid'),
        ]
    )]
    public function products(Request $request, string $shopId): JsonResponse
    {
        $shop = ChannelShop::with('channel')->where('shop_id', $shopId)->first();

        if (!$shop) {
            return $this->errorResponse('Toko tidak ditemukan', 404);
        }

        $validStatuses = ['synced', 'pending', 'failed', 'syncing', 'deactivated'];
        if ($request->filled('sync_status') && !in_array($request->query('sync_status'), $validStatuses, true)) {
            return $this->errorResponse('sync_status tidak valid. Nilai yang diizinkan: ' . implode(', ', $validStatuses), 422);
        }

        $query = ProductChannelMapping::with([
                'product:id,name,sku,status',
                'product.media',
                'variantMappings.variant:id,product_id,sku,sell_price',
            ])
            ->where('channel_shop_id', $shop->id);

        if ($request->filled('sync_status')) {
            $query->where('sync_status', $request->query('sync_status'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->whereHas('product', fn ($q) =>
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('sku', 'ilike', "%{$search}%")
            );
        }

        $limit   = (int) $request->input('limit', 20);
        $results = $query->orderByDesc('last_synced_at')->paginate($limit);

        $data = $results->through(function ($m) {
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
                    'sell_price'      => $vm->variant->sell_price !== null ? (float) $vm->variant->sell_price : null,
                    'override_price'  => $vm->override_price !== null ? (float) $vm->override_price : null,
                    'synced_price'    => $vm->synced_price !== null ? (float) $vm->synced_price : null,
                    'synced_stock'    => $vm->synced_stock,
                ])->values(),
            ];
        });

        return $this->successPaginatedResponse($data, 'Channel monitor products');
    }

    // ==================== Internal ====================

    private function buildShopSummary(ChannelShop $shop): array
    {
        $stats = ProductChannelMapping::where('channel_shop_id', $shop->id)
            ->selectRaw('sync_status, count(*) as count')
            ->groupBy('sync_status')
            ->pluck('count', 'sync_status')
            ->toArray();

        $total = array_sum($stats);

        $lastSyncedAt = ProductChannelMapping::where('channel_shop_id', $shop->id)
            ->whereNotNull('last_synced_at')
            ->max('last_synced_at');

        $failedLast24h = ProductChannelMapping::where('channel_shop_id', $shop->id)
            ->where('sync_status', 'failed')
            ->where('updated_at', '>=', now()->subDay())
            ->count();

        return [
            'channel_shop_id' => $shop->id,
            'shop_id'         => $shop->shop_id,
            'shop_name'       => $shop->shop_name,
            'channel'         => $shop->channel->name ?? null,
            'channel_code'    => $shop->channel->code ?? null,
            'is_active'       => $shop->is_active,
            'stats'           => [
                'total_linked' => $total,
                'synced'       => (int) ($stats['synced']      ?? 0),
                'pending'      => (int) ($stats['pending']     ?? 0),
                'syncing'      => (int) ($stats['syncing']     ?? 0),
                'failed'       => (int) ($stats['failed']      ?? 0),
                'deactivated'  => (int) ($stats['deactivated'] ?? 0),
            ],
            'last_synced_at'  => $lastSyncedAt,
            'failed_last_24h' => $failedLast24h,
        ];
    }
}
