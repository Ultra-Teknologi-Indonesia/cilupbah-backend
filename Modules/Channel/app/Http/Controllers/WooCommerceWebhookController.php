<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Helpers\WooCommerceSignature;
use Modules\Channel\Jobs\ProcessWooCommerceWebhook;
use Modules\Channel\Repositories\ChannelShopRepository;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'WooCommerce', description: 'Integrasi WooCommerce')]
class WooCommerceWebhookController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ChannelShopRepository $shopRepository,
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

        $shop = $this->shopRepository->findConnectedByStoreUrl($source);

        if (! $shop) {
            Log::warning('WooCommerce webhook: toko tidak ditemukan.', ['source' => $source]);

            return response('', 200);
        }

        $secret = (string) $shop->webhook_secret;

        $mustVerify = config('services.woocommerce.verify_webhook_signature', true)
            || app()->environment('production')
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
            return response('', 200);
        }

        if (! $this->isFirstDelivery($shop->shop_id, $topic, $payload)) {
            return response('', 200);
        }

        ProcessWooCommerceWebhook::dispatch(
            $shop->shop_id,
            $topic,
            (string) $payload['id'],
            $payload,
        );

        return response('', 200);
    }

    protected function isFirstDelivery(string $shopId, string $topic, array $payload): bool
    {

        $key = ProcessWooCommerceWebhook::idempotencyKey($shopId, $topic, $payload);

        return Cache::add($key, 1, now()->addMinutes(10));
    }
}
