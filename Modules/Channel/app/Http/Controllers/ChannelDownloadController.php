<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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
            $count = $this->downloadService->download($channel, $data['shop_id']);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 422);
        } catch (\Exception $e) {
            return $this->errorResponse('Gagal menarik produk: ' . $e->getMessage(), 500);
        }

        return $this->successResponse(
            ['pulled_count' => $count],
            "Berhasil menarik {$count} produk dari channel {$channel}"
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
            $results = $this->downloadService->downloadBulk($channel, $data['shop_ids']);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->successResponse(['details' => $results], 'Proses download massal selesai');
    }
}
