<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Channel\Models\ChannelShop;
use Modules\Inventory\Http\Requests\OrderAuditReportRequest;
use Modules\Inventory\Http\Resources\OrderAuditReportResource;
use Modules\Inventory\Services\OrderAuditReportService;
use Modules\Inventory\Services\OrderCutoverLookupService;
use RuntimeException;

final class OrderAuditController extends Controller
{
    use ApiResponse;

    public function shops(): JsonResponse
    {
        $shops = ChannelShop::query()
            ->with('channel:id,code,name')
            ->where('is_active', true)
            ->where('order_sync_enabled', true)
            ->whereNull('disconnected_at')
            ->whereHas('channel', fn ($query) => $query->whereIn('code', ['shopee', 'tiktok', 'lazada', 'woocommerce']))
            ->orderBy('shop_name')
            ->get(['id', 'channel_id', 'shop_id', 'shop_name'])
            ->map(fn (ChannelShop $shop): array => [
                'id' => (string) $shop->id,
                'shop_id' => (string) $shop->shop_id,
                'shop_name' => (string) $shop->shop_name,
                'channel' => (string) ($shop->channel?->code ?? ''),
                'channel_name' => (string) ($shop->channel?->name ?? $shop->channel?->code ?? ''),
            ])->values();

        return $this->successResponse($shops, 'Daftar toko untuk pull marketplace berhasil diambil.');
    }

    public function lookup(Request $request, OrderCutoverLookupService $service): JsonResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:128'],
        ]);

        return $this->successResponse(
            $service->lookup((string) $validated['reference']),
            'Audit pesanan berhasil diambil.',
        );
    }

    public function report(OrderAuditReportRequest $request, OrderAuditReportService $service): JsonResponse
    {
        $result = $service->paginate();

        return $this->successResponse(
            (new OrderAuditReportResource($result))->resolve($request),
            'Audit pesanan berhasil diambil.',
            200,
            [
                'current_page' => $result->paginator->currentPage(),
                'last_page' => $result->paginator->lastPage(),
                'per_page' => $result->paginator->perPage(),
                'total' => $result->paginator->total(),
            ],
        );
    }

    public function replay(Request $request, OrderCutoverLookupService $service): JsonResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:128'],
            'confirmation' => ['required', 'in:REPLAY-ORDER'],
        ]);

        try {
            return $this->successResponse(
                $service->include((string) $validated['reference']),
                'Permintaan order diproses.',
            );
        } catch (RuntimeException $exception) {
            return $this->errorResponse(
                $exception->getMessage(),
                422,
                null,
                'Order belum dapat ditarik',
            );
        }
    }

    public function delete(Request $request, OrderCutoverLookupService $service): JsonResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:128'],
            'confirmation' => ['required', 'in:DELETE-ORDER'],
        ]);

        try {
            return $this->successResponse(
                $service->delete((string) $validated['reference']),
                'Order berhasil dihapus.',
            );
        } catch (RuntimeException $exception) {
            return $this->errorResponse(
                $exception->getMessage(),
                422,
                null,
                'Order tidak dapat dihapus',
            );
        }
    }

    public function pullMarketplace(Request $request, OrderCutoverLookupService $service): JsonResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:128'],
            'channel' => ['required', 'in:shopee,tiktok,lazada,woocommerce'],
            'shop_id' => ['required', 'string', 'max:128'],
            'confirmation' => ['required', 'in:PULL-MARKETPLACE'],
        ]);

        try {
            return $this->successResponse(
                $service->pullMarketplace(
                    (string) $validated['reference'],
                    (string) $validated['channel'],
                    (string) $validated['shop_id'],
                ),
                'Pull marketplace diproses.',
            );
        } catch (RuntimeException $exception) {
            return $this->errorResponse(
                $exception->getMessage(),
                422,
                null,
                'Order belum dapat ditarik dari marketplace',
            );
        }
    }
}
