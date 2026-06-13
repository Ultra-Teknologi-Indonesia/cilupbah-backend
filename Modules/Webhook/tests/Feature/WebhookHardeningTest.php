<?php

namespace Modules\Webhook\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Modules\Webhook\Jobs\DispatchWebhookEventJob;
use Modules\Webhook\Jobs\SendWebhookJob;
use Modules\Webhook\Models\WebhookDelivery;
use Modules\Webhook\Models\WebhookSubscription;
use Modules\Webhook\Observers\SalesOrderWebhookObserver;
use Modules\Webhook\Repositories\WebhookDeliveryRepository;
use Modules\Webhook\Repositories\WebhookSubscriptionRepository;
use Modules\Webhook\Services\WebhookDispatcherService;
use Modules\Webhook\Support\WebhookEvent;
use Modules\Webhook\Support\WebhookUrlGuard;
use Tests\TestCase;

class WebhookHardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        WebhookDispatcherService::flushCache();
    }

    private function makeSubscription(array $override = []): WebhookSubscription
    {
        $sub = WebhookSubscription::create(array_merge([
            'event' => WebhookEvent::PRODUCT,
            'target_url' => 'https://example.test/hook',
            'secret' => 'sec-123',
            'is_active' => true,
        ], $override));
        WebhookDispatcherService::flushCache();

        return $sub;
    }

    private function makeDelivery(WebhookSubscription $sub, array $override = []): WebhookDelivery
    {
        return WebhookDelivery::create(array_merge([
            'subscription_id' => $sub->id,
            'event' => WebhookEvent::PRODUCT,
            'event_id' => (string) Str::uuid7(),
            'payload' => ['x' => 1],
            'status' => WebhookDelivery::STATUS_FAILED,
            'status_code' => 500,
            'attempts' => 3,
            'last_error' => 'boom',
        ], $override));
    }

    public function test_url_guard_blocks_internal_and_non_https(): void
    {
        $guard = new WebhookUrlGuard();

        $this->assertFalse($guard->isSafe('http://example.test/hook'));     
        $this->assertFalse($guard->isSafe('https://127.0.0.1/hook'));        
        $this->assertFalse($guard->isSafe('https://localhost/hook'));        
        $this->assertFalse($guard->isSafe('https://169.254.169.254/'));      
        $this->assertFalse($guard->isSafe('https://10.0.0.5/hook'));         
        $this->assertTrue($guard->isSafe('https://example.test/hook'));      
    }

    public function test_register_rejects_internal_target_url(): void
    {
        foreach (['http://example.test/hook', 'https://127.0.0.1/hook', 'https://169.254.169.254/'] as $url) {
            $this->actingAs($this->user, 'sanctum')
                ->postJson('/api/v1/webhooks', ['event' => 'product', 'target_url' => $url])
                ->assertStatus(422);
        }
    }

    public function test_register_accepts_public_https(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/webhooks', ['event' => 'product', 'target_url' => 'https://example.test/hook'])
            ->assertStatus(201);
    }

    public function test_send_blocks_internal_url_without_throwing(): void
    {
        Http::fake();
        $sub = $this->makeSubscription(['target_url' => 'https://127.0.0.1/hook']);
        $delivery = $this->makeDelivery($sub, ['status' => WebhookDelivery::STATUS_PENDING]);

        (new SendWebhookJob($delivery->id))->handle(
            app(WebhookDeliveryRepository::class),
            app(WebhookSubscriptionRepository::class),
        );

        $delivery->refresh();
        $this->assertSame(WebhookDelivery::STATUS_FAILED, $delivery->status);
        $this->assertStringContainsString('diblokir', $delivery->last_error);
        Http::assertNothingSent();
    }

    public function test_lists_deliveries_for_subscription(): void
    {
        $sub = $this->makeSubscription();
        $delivery = $this->makeDelivery($sub);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/webhooks/{$sub->id}/deliveries")
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $delivery->id)
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('meta.per_page', 10);
    }

    public function test_deliveries_non_uuid_returns_404(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/webhooks/not-a-uuid/deliveries')
            ->assertStatus(404);
    }

    public function test_redeliver_requeues_and_resets_status(): void
    {
        Queue::fake();
        $sub = $this->makeSubscription();
        $delivery = $this->makeDelivery($sub);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/webhook-deliveries/{$delivery->id}/redeliver")
            ->assertStatus(202);

        $delivery->refresh();
        $this->assertSame(WebhookDelivery::STATUS_PENDING, $delivery->status);
        $this->assertNull($delivery->last_error);
        Queue::assertPushed(SendWebhookJob::class);
    }

    public function test_redeliver_unknown_returns_404(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/webhook-deliveries/'.Str::uuid7().'/redeliver')
            ->assertStatus(404);
    }

    public function test_auto_disables_after_threshold_failures(): void
    {
        $sub = $this->makeSubscription(['consecutive_failures' => 0]);
        $repo = app(WebhookSubscriptionRepository::class);

        $repo->registerFailureAndMaybeDisable($sub->id, 2);
        $this->assertTrue($sub->fresh()->is_active);

        $repo->registerFailureAndMaybeDisable($sub->id, 2);
        $this->assertFalse($sub->fresh()->is_active);
        $this->assertSame(2, $sub->fresh()->consecutive_failures);
    }

    public function test_send_success_marks_delivered_and_resets_failures(): void
    {
        Http::fake(['example.test/*' => Http::response('ok', 200)]);
        $sub = $this->makeSubscription(['consecutive_failures' => 4]);
        $delivery = $this->makeDelivery($sub, ['status' => WebhookDelivery::STATUS_PENDING, 'attempts' => 0]);

        (new SendWebhookJob($delivery->id))->handle(
            app(WebhookDeliveryRepository::class),
            app(WebhookSubscriptionRepository::class),
        );

        $delivery->refresh();
        $this->assertSame(WebhookDelivery::STATUS_SUCCESS, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(0, $sub->fresh()->consecutive_failures);
    }

    public function test_send_treats_3xx_as_delivered(): void
    {
        Http::fake(['example.test/*' => Http::response('', 302)]);
        $sub = $this->makeSubscription();
        $delivery = $this->makeDelivery($sub, ['status' => WebhookDelivery::STATUS_PENDING, 'attempts' => 0]);

        (new SendWebhookJob($delivery->id))->handle(
            app(WebhookDeliveryRepository::class),
            app(WebhookSubscriptionRepository::class),
        );

        $this->assertSame(WebhookDelivery::STATUS_SUCCESS, $delivery->fresh()->status);
    }

    public function test_dispatch_job_idempotent_per_event_id(): void
    {
        Queue::fake();
        $sub = $this->makeSubscription();
        $eventId = (string) Str::uuid7();

        $run = fn () => (new DispatchWebhookEventJob('product', ['a' => 1], $eventId))->handle(
            app(WebhookSubscriptionRepository::class),
            app(WebhookDeliveryRepository::class),
        );

        $run();
        $run();

        $this->assertSame(1, WebhookDelivery::where('subscription_id', $sub->id)->where('event_id', $eventId)->count());
        Queue::assertPushed(SendWebhookJob::class, 1);
    }

    public function test_salesorder_payload_includes_channel_context(): void
    {
        $this->makeSubscription(['event' => WebhookEvent::SALES_ORDER]);
        Queue::fake();

        $order = new SalesOrder([
            'salesorder_no' => 'SO-9',
            'status' => 'reserved',
            'grand_total' => 100,
            'source' => 'tiktok',
            'channel_status' => 'AWAITING_SHIPMENT',
        ]);
        $order->id = (string) Str::uuid7();

        (new SalesOrderWebhookObserver())->created($order);

        Queue::assertPushed(
            DispatchWebhookEventJob::class,
            fn ($job) => $job->payload['source'] === 'tiktok'
                && $job->payload['channel_status'] === 'AWAITING_SHIPMENT'
                && $job->payload['action'] === 'created'
        );
    }
}
