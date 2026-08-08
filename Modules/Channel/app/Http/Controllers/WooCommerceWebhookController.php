<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Helpers\WooCommerceSignature;
use Modules\Channel\Jobs\ProcessWooCommerceWebhook;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Repositories\ChannelWebhookInboxRepository;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'WooCommerce', description: 'Integrasi WooCommerce')]
class WooCommerceWebhookController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ChannelShopRepository $shopRepository,
        protected ChannelWebhookInboxRepository $inbox,
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

            $this->quarantine($topic, $source, $rawBody, 'Toko WooCommerce tidak ditemukan dari source URL.');

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

            $this->quarantine($topic, $source, $rawBody, 'Payload WooCommerce tidak valid atau tanpa id.', is_array($payload) ? $payload : null);

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
        return $this->inbox->recordFirstDelivery(
            'woocommerce',
            $shopId,
            ProcessWooCommerceWebhook::idempotencyKey($shopId, $topic, $payload),
            $topic,
            $payload,
        ) !== null;
    }

    protected function quarantine(string $topic, string $source, string $rawBody, string $reason, ?array $payload = null): void
    {
        $eventKey = 'woocommerce:anomaly:' . md5($source . '|' . $topic . '|' . $rawBody);

        $row = $this->inbox->recordFirstDelivery(
            'woocommerce',
            null,
            $eventKey,
            $topic,
            $payload ?? ['_source' => $source, '_raw' => mb_substr($rawBody, 0, 5000)],
        );

        if ($row === null) {
            return; 
        }

        $row->markFailed($reason);

        \Modules\Sales\Jobs\AdminAlertJob::dispatch(
            'WooCommerce webhook tidak dapat diproses',
            $reason,
            ['source' => $source, 'topic' => $topic, 'inbox_id' => $row->id],
        );
    }
}
