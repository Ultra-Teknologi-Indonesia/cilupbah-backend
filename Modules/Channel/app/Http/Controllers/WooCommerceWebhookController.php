<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Helpers\WooCommerceSignature;
use Modules\Channel\Jobs\ProcessWooCommerceWebhook;
use Modules\Channel\Models\ChannelShop;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'WooCommerce', description: 'Integrasi WooCommerce')]
class WooCommerceWebhookController extends Controller
{
    use ApiResponse;

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

        $shop = $this->resolveShop($source);

        if (! $shop) {
            Log::warning('WooCommerce webhook: toko tidak ditemukan.', ['source' => $source]);

            return response('', 200);
        }

        if (config('services.woocommerce.verify_webhook_signature', true)) {
            $provided = (string) $request->header('x-wc-webhook-signature', '');

            if (! WooCommerceSignature::verify($rawBody, (string) $shop->webhook_secret, $provided)) {
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

        if (! $this->isFirstDelivery($request, $payload)) {
            return response('', 200);
        }

        ProcessWooCommerceWebhook::dispatch($shop->shop_id, $topic, (string) $payload['id']);

        return response('', 200);
    }

    protected function resolveShop(string $source): ?ChannelShop
    {
        if ($source === '') {
            return null;
        }

        $normalized = rtrim($source, '/');

        return ChannelShop::whereNull('disconnected_at')
            ->where(function ($q) use ($normalized) {
                $q->where('store_url', $normalized)
                    ->orWhere('store_url', $normalized . '/');
            })
            ->first();
    }

    protected function isFirstDelivery(Request $request, array $payload): bool
    {
        $key = 'woocommerce:webhook:' . md5(json_encode([
            $request->header('x-wc-webhook-id'),
            $request->header('x-wc-webhook-delivery-id'),
            $request->header('x-wc-webhook-topic'),
            $payload['id'] ?? '',
            $payload['date_modified'] ?? ($payload['status'] ?? ''),
        ]));

        return Cache::add($key, 1, now()->addDay());
    }
}
