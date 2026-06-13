<?php

namespace Modules\Webhook\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Webhook\Http\Requests\StoreWebhookSubscriptionRequest;
use Modules\Webhook\Http\Resources\WebhookDeliveryResource;
use Modules\Webhook\Http\Resources\WebhookSubscriptionResource;
use Modules\Webhook\Services\WebhookDeliveryService;
use Modules\Webhook\Services\WebhookSubscriptionService;

class WebhookController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly WebhookSubscriptionService $service,
        private readonly WebhookDeliveryService $deliveries,
    ) {
    }

    public function index(): JsonResponse
    {
        return $this->successPaginatedResponse(
            WebhookSubscriptionResource::collection($this->service->list()),
            'Daftar webhook subscription berhasil diambil'
        );
    }

    /** Jubelio: POST /systemsetting/webhook + POST /webhooks — Create/Edit Webhook subscription. */
    public function store(StoreWebhookSubscriptionRequest $request): JsonResponse
    {
        $subscription = $this->service->create($request->validated());

        // Secret hanya ditampilkan SEKALI saat pembuatan (untuk verifikasi signature).
        return $this->successResponse([
            'subscription' => new WebhookSubscriptionResource($subscription),
            'secret' => $subscription->secret,
        ], 'Webhook subscription berhasil dibuat', 201);
    }

    public function show(string $id): JsonResponse
    {
        $subscription = $this->resolve($id);
        if (! $subscription) {
            return $this->errorResponse('Webhook subscription tidak ditemukan', 404);
        }

        return $this->successResponse(new WebhookSubscriptionResource($subscription), 'Detail webhook subscription');
    }

    public function update(StoreWebhookSubscriptionRequest $request, string $id): JsonResponse
    {
        $subscription = $this->resolve($id);
        if (! $subscription) {
            return $this->errorResponse('Webhook subscription tidak ditemukan', 404);
        }

        $subscription = $this->service->update($subscription, $request->validated());

        return $this->successResponse(new WebhookSubscriptionResource($subscription), 'Webhook subscription berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $subscription = $this->resolve($id);
        if (! $subscription) {
            return $this->errorResponse('Webhook subscription tidak ditemukan', 404);
        }

        $this->service->delete($subscription);

        return $this->successResponse(null, 'Webhook subscription berhasil dihapus');
    }

    /** Riwayat pengiriman webhook untuk satu subscription. */
    public function deliveries(string $id): JsonResponse
    {
        $subscription = $this->resolve($id);
        if (! $subscription) {
            return $this->errorResponse('Webhook subscription tidak ditemukan', 404);
        }

        return $this->successPaginatedResponse(
            WebhookDeliveryResource::collection($this->deliveries->listForSubscription($subscription->id)),
            'Daftar pengiriman webhook'
        );
    }

    /** Kirim ulang satu delivery yang gagal. */
    public function redeliver(string $delivery): JsonResponse
    {
        $record = $this->deliveries->find($delivery);
        if (! $record) {
            return $this->errorResponse('Pengiriman webhook tidak ditemukan', 404);
        }

        $this->deliveries->redeliver($record);

        return $this->successResponse(
            new WebhookDeliveryResource($record->fresh()),
            'Pengiriman ulang webhook diantrekan',
            202
        );
    }

    /** Guard format UUID agar id non-UUID -> 404 (bukan 500 cast Postgres). */
    private function resolve(string $id)
    {
        $normalized = str_replace('-', '', $id);
        if (strlen($normalized) !== 32 || ! ctype_xdigit($normalized)) {
            return null;
        }

        return $this->service->find($id);
    }
}
