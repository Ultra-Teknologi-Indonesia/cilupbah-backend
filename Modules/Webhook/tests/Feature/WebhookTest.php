<?php

namespace Modules\Webhook\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Webhook\Jobs\DispatchWebhookEventJob;
use Modules\Webhook\Models\WebhookSubscription;
use Modules\Webhook\Services\WebhookDispatcherService;
use Modules\Webhook\Support\WebhookEvent;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createPrivilegedUser();
        WebhookDispatcherService::flushCache();
    }

    private function subscribe(string $event): WebhookSubscription
    {
        $sub = WebhookSubscription::create([
            'event' => $event,
            'target_url' => 'https://example.test/hook',
            'secret' => 'secret-123',
            'is_active' => true,
        ]);
        WebhookDispatcherService::flushCache();

        return $sub;
    }

    private function makeProduct(): Product
    {
        $category = Category::create(['name' => 'Cat WH', 'is_active' => true]);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Produk WH',
            'status' => 'master',
            'is_active' => true,
        ]);
    }

    public function test_can_register_subscription_via_api(): void
    {
        $payload = ['event' => WebhookEvent::PRODUCT, 'target_url' => 'https://example.test/hook'];

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/systemsetting/webhook', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.subscription.event', 'product')
            ->assertJsonStructure(['data' => ['subscription' => ['id'], 'secret']]);
        $this->assertDatabaseHas('webhook_subscriptions', ['event' => 'product']);
    }

    public function test_invalid_event_returns_422_not_500(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/systemsetting/webhook', ['event' => 'ngawur', 'target_url' => 'not-a-url']);

        $response->assertStatus(422);
    }

    public function test_observer_enqueues_webhook_when_subscribed(): void
    {
        $this->subscribe(WebhookEvent::PRODUCT);
        Queue::fake();

        $this->makeProduct();

        Queue::assertPushed(DispatchWebhookEventJob::class, fn ($job) => $job->event === WebhookEvent::PRODUCT);
    }

    public function test_no_webhook_when_no_subscription(): void
    {
        Queue::fake();

        $this->makeProduct();

        Queue::assertNotPushed(DispatchWebhookEventJob::class);
    }

    public function test_domain_operation_never_500_even_if_webhook_layer_throws(): void
    {
        $this->subscribe(WebhookEvent::PRODUCT);

        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('queue down'));

        $product = $this->makeProduct();

        $this->assertNotNull($product->id);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }
}
