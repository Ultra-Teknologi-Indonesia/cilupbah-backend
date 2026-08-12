<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Channel\Http\Resources\DownloadTransactionResource;
use Modules\Channel\Services\ChannelDownloadService;
use OpenApi\Attributes as OA;
use App\Traits\ApiResponse;

class ChannelDownloadController extends Controller
{
    use ApiResponse;

    protected ChannelDownloadService $downloadService;

    public function __construct(ChannelDownloadService $downloadService)
    {
        $this->downloadService = $downloadService;
    }

    #[OA\Post(
        path: "/api/v1/{channel}/download",
        summary: "Download produk dari satu toko (generic per channel)",
        description: "Generalisasi pull produk dari marketplace per toko. Produk masuk dengan status 'download'.",
        tags: ["Channel Download"]
    )]
    #[OA\Parameter(name: "channel", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: "shop_id", type: "string")
    ]))]
    #[OA\Response(response: 200, description: "Download berhasil")]
    #[OA\Response(response: 422, description: "Channel tidak didukung / shop_id kosong")]
    public function download(Request $request, string $channel): JsonResponse
    {
        $data = $request->validate(['shop_id' => 'required|string']);

        try {
            $transaction = $this->downloadService->download($channel, $data['shop_id'], $request->user()?->id);
        } catch (\RuntimeException $e) {
            return $this->errorResponse(
                'Gagal memulai download.',
                $e->getCode() ?: 422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memulai download.',
                500,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse(
            new DownloadTransactionResource($transaction->load('channelShop.channel')),
            "Download dari channel {$channel} diantrekan",
            202
        );
    }

    #[OA\Post(
        path: "/api/v1/{channel}/download/bulk",
        summary: "Download produk dari banyak toko (bulk)",
        tags: ["Channel Download"]
    )]
    #[OA\Parameter(name: "channel", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: "shop_ids", type: "array", items: new OA\Items(type: "string"))
    ]))]
    #[OA\Response(response: 200, description: "Proses download massal selesai")]
    public function downloadBulk(Request $request, string $channel): JsonResponse
    {
        $data = $request->validate([
            'shop_ids' => 'required|array|min:1',
            'shop_ids.*' => 'string',
        ]);

        try {
            $transactions = $this->downloadService->downloadBulk($channel, $data['shop_ids'], $request->user()?->id);
        } catch (\RuntimeException $e) {
            return $this->errorResponse(
                'Gagal memulai download massal.',
                $e->getCode() ?: 422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'Gagal memulai download massal.',
                500,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        $collection = \Illuminate\Database\Eloquent\Collection::make($transactions)
            ->load('channelShop.channel')
            ->map(fn ($trx) => (new DownloadTransactionResource($trx))->resolve($request));

        return $this->successResponse($collection, 'Download massal diantrekan', 202);
    }

    #[OA\Get(
        path: "/api/v1/{channel}/download/search",
        summary: "Cari produk di marketplace (Download Satuan)",
        tags: ["Channel Download"]
    )]
    #[OA\Parameter(name: "channel", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "shop_id", in: "query", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "q", in: "query", required: false, schema: new OA\Schema(type: "string"))]
    public function search(Request $request, string $channel): JsonResponse
    {
        $data = $request->validate([
            'shop_id' => 'required|string',
            'q' => 'nullable|string',
            'offset' => 'nullable|integer|min:0',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $offset = (int) ($data['offset'] ?? 0);
        $limit = (int) ($data['limit'] ?? 20);

        try {
            $result = $this->downloadService->searchProducts($channel, $data['shop_id'], $data['q'] ?? '', $offset, $limit);
        } catch (\RuntimeException $e) {
            return $this->errorResponse(
                'Gagal mencari produk.',
                $e->getCode() ?: 422,
                ['detail' => $e->getMessage()],
                'Terjadi kesalahan',
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal mencari produk.',
                500,
                ['detail' => $e->getMessage()],
                'Terjadi kesalahan',
            );
        }

        return $this->successResponse($result['items'], 'Pencarian produk channel berhasil', 200, [
            'next_offset' => $result['next_offset'],
            'has_more' => $result['has_more'],
        ]);
    }

    #[OA\Post(
        path: "/api/v1/{channel}/download-product",
        summary: "Download satu produk dari marketplace by external id (Download Satuan)",
        tags: ["Channel Download"]
    )]
    #[OA\Parameter(name: "channel", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: "shop_id", type: "string"),
        new OA\Property(property: "external_product_id", type: "string"),
    ]))]
    public function downloadProduct(Request $request, string $channel): JsonResponse
    {
        $data = $request->validate([
            'shop_id' => 'required|string',
            'external_product_id' => 'required|string',
        ]);

        try {
            $this->downloadService->downloadProductManual($channel, $data['shop_id'], $data['external_product_id'], $request->user()?->id);
        } catch (\RuntimeException $e) {
            return $this->errorResponse(
                'Gagal mengunduh produk.',
                $e->getCode() ?: 422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal mengunduh produk.',
                500,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse(null, 'Produk berhasil diunduh dari channel');
    }
}
