<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Channel\Http\Requests\UpdateChannelShopRequest;
use Modules\Channel\Http\Resources\ChannelResource;
use Modules\Channel\Http\Resources\ChannelShopResource;
use Modules\Channel\Services\ChannelService;
use Modules\Channel\Services\TikTokAuthService;

class ChannelController extends Controller
{
    use ApiResponse;

    protected ChannelService $channelService;
    protected TikTokAuthService $authService;

    public function __construct(ChannelService $channelService, TikTokAuthService $authService)
    {
        $this->channelService = $channelService;
        $this->authService = $authService;
    }

    public function stores(): JsonResponse
    {
        return $this->successPaginatedResponse(
            ChannelShopResource::collection($this->channelService->getConnectedStores()),
            'Daftar toko marketplace berhasil diambil'
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

    public function index(Request $request)
    {
        try {
            $channels = $this->channelService->getPaginatedChannels();

            if ($request->is('api/*') || $request->wantsJson()) {
                return $this->successPaginatedResponse(
                    ChannelResource::collection($channels),
                    'Daftar channel berhasil diambil.'
                );
            }

            return view('channel::index', compact('channels'));
        } catch (\Exception $e) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return $this->errorResponse($e->getMessage(), 500);
            }
            return back()->with('error', $e->getMessage());
        }
    }

    public function disconnectShop(Request $request, string $id)
    {
        $isApi = $request->is('api/*') || $request->wantsJson();

        try {
            $this->channelService->disconnectStore($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            if ($isApi) {
                throw $e; 
            }
            return back()->with('error', 'Toko tidak ditemukan.');
        } catch (\Throwable $e) {
            if ($isApi) {
                return $this->errorResponse('Gagal memutuskan toko: ' . $e->getMessage(), 422);
            }
            return back()->with('error', 'Gagal memutuskan toko: ' . $e->getMessage());
        }

        if ($isApi) {
            return $this->successResponse(null, 'Toko berhasil diputuskan dari channel.');
        }

        return back()->with('success', 'Toko berhasil diputuskan dari channel.');
    }

    public function refreshShopToken(Request $request, string $id)
    {
        try {
            $result = $this->authService->refreshStoreToken($id);
            if ($request->is('api/*') || $request->wantsJson()) {
                return $this->successResponse($result, "Token toko berhasil diperbarui.");
            }
            return back()->with('success', 'Token toko berhasil diperbarui.');
        } catch (\Exception $e) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return $this->errorResponse('Gagal memperbarui token: ' . $e->getMessage(), 500);
            }
            return back()->with('error', 'Gagal memperbarui token: ' . $e->getMessage());
        }
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
