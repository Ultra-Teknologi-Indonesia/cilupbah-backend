<?php

namespace Modules\Webhook\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Webhook\Http\Requests\StoreWebhookSubscriptionRequest;
use Modules\Webhook\Http\Resources\WebhookSubscriptionResource;
use Modules\Webhook\Services\WebhookSubscriptionService;

class WebhookController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly WebhookSubscriptionService $service,
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
