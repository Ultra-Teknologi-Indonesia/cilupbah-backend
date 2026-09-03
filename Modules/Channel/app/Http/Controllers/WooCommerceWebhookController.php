<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Helpers\WooCommerceSignature;
use Modules\Channel\Jobs\ProcessWooCommerceWebhook;
use Modules\Channel\Services\ChannelService;
use Modules\Channel\Services\ChannelWebhookService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'WooCommerce', description: 'Integrasi WooCommerce')]
class WooCommerceWebhookController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ChannelService $channelService,
        protected ChannelWebhookService $webhookService,
    ) {}

    public function verify()
    {
        return $this->successResponse(['service' => 'ready'], 'WooCommerce webhook service aktif.');
    }

    #[OA\Post(
        path: '/api/v1/woocommerce/webhook',
        summary: 'Webhook masuk WooCommerce (order/produk)',
        tags: ['WooCommerce'],
        responses: [
            new OA\Response(response: 200, description: 'Diterima'),
            new OA\Response(response: 401, description: 'Signature tidak valid'),
        ]
    )]
    public function handle(Request $request)
    {
        $rawBody = $request->getContent();
        $topic = (string) $request->header('x-wc-webhook-topic', '');
        $source = (string) $request->header('x-wc-webhook-source', '');

        $shop = $this->channelService->findConnectedStoreByUrl($source);

        if (! $shop) {

            $this->webhookService->quarantine($topic, $source, $rawBody, 'Toko WooCommerce tidak ditemukan dari source URL.');

            return response('', 200);
        }

        $secret = (string) $shop->webhook_secret;

        $mustVerify = config('services.woocommerce.verify_webhook_signature', true)
            || config('app.env') === 'production'
            || $secret === '';

        if ($mustVerify) {
            if ($secret === '') {
                Log::warning('WooCommerce webhook secret kosong — ditolak.', [
                    'source' => $source,
                    'topic' => $topic,
                ]);

                return response('', 401);
            }

            $provided = (string) $request->header('x-wc-webhook-signature', '');

            if (! WooCommerceSignature::verify($rawBody, $secret, $provided)) {
                Log::warning('WooCommerce webhook signature tidak valid', [
                    'source' => $source,
                    'topic' => $topic,
                ]);

                return response('', 401);
            }
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload) || empty($payload['id'])) {

            $this->webhookService->quarantine($topic, $source, $rawBody, 'Payload WooCommerce tidak valid atau tanpa id.', is_array($payload) ? $payload : null);

            return response('', 200);
        }

        if (! $this->isFirstDelivery($shop->shop_id, $topic, $payload)) {
            return response('', 200);
        }

        $this->webhookService->dispatchWooCommerce($shop->shop_id, $topic, $payload);

        return response('', 200);
    }

    protected function isFirstDelivery(string $shopId, string $topic, array $payload): bool
    {
        return $this->webhookService->recordFirstDelivery(
            'woocommerce',
            $shopId,
            ProcessWooCommerceWebhook::idempotencyKey($shopId, $topic, $payload),
            $topic,
            $payload,
        ) !== null;
    }

    protected function quarantine(string $topic, string $source, string $rawBody, string $reason, ?array $payload = null): void
    {
        $this->webhookService->quarantine($topic, $source, $rawBody, $reason, $payload);
    }
}
