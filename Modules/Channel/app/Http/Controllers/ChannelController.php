<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Channel\Http\Requests\UpdateChannelShopRequest;
use Modules\Channel\Http\Resources\ChannelResource;
use Modules\Channel\Http\Resources\ChannelShopResource;
use Modules\Channel\Http\Resources\StockAllocationResource;
use Modules\Channel\Services\ChannelService;

class ChannelController extends Controller
{
    use ApiResponse;

    protected ChannelService $channelService;

    public function __construct(ChannelService $channelService)
    {
        $this->channelService = $channelService;
    }

    public function stores(): JsonResponse
    {
        return $this->successPaginatedResponse(
            ChannelShopResource::collection($this->channelService->getConnectedStores()),
            'Daftar toko marketplace berhasil diambil'
        );
    }

    public function stockAllocation(): JsonResponse
    {
        return $this->successPaginatedResponse(
            StockAllocationResource::collection($this->channelService->getStockAllocationStores()),
            'Daftar alokasi stok toko berhasil diambil'
        );
    }

    public function updateStore(UpdateChannelShopRequest $request, string $id): JsonResponse
    {
        $shop = $this->channelService->updateStoreFlags($id, $request->validated());

        return $this->successResponse(
            new ChannelShopResource($shop),
            'Pengaturan toko berhasil diperbarui.'
        );
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $channels = $this->channelService->getPaginatedChannels();

            return $this->successPaginatedResponse(
                ChannelResource::collection($channels),
                'Daftar channel berhasil diambil.'
            );
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function disconnectShop(Request $request, string $id): JsonResponse
    {
        try {
            $this->channelService->disconnectStore($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal memutuskan toko: ' . $e->getMessage(), 422);
        }

        return $this->successResponse(null, 'Toko berhasil diputuskan dari channel.');
    }

    public function printLabelCapabilities(Request $request): JsonResponse
    {
        $source = strtolower((string) $request->query('source', ''));
        $registry = (array) config('channel_print_capabilities', []);
        $entry = $registry[$source] ?? null;

        $data = [
            'source' => $source,
            'document_types' => $entry['document_types'] ?? [],
            'document_sizes' => $entry['document_sizes'] ?? [],
        ];

        return $this->successResponse($data, 'Kapabilitas cetak label berhasil diambil');
    }
}
