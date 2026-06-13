<?php

namespace Modules\Channel\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Adapters\AdapterFactory;
use Modules\Channel\Adapters\LazadaAdapter;
use Modules\Channel\Exceptions\TokenExpiredException;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\LazadaClient;
use Tests\TestCase;

class LazadaStoreApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Channel $lazada;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.lazada.app_key' => 'test_key',
            'services.lazada.app_secret' => 'test_secret',
            'services.lazada.auth_url' => 'https://auth.lazada.com',
            'services.lazada.base_url' => 'https://api.lazada.co.id/rest',
        ]);

        $this->user = User::factory()->create();
        $this->lazada = Channel::create(['code' => 'lazada', 'name' => 'Lazada', 'is_active' => true]);
    }

    private function makeShop(array $override = []): ChannelShop
    {
        return ChannelShop::create(array_merge([
            'channel_id' => $this->lazada->id,
            'shop_id' => 'LZ-' . fake()->unique()->numerify('######'),
            'shop_name' => 'Toko Lazada',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addDays(7),
            'refresh_token_expires_at' => now()->addDays(30),
            'is_active' => true,
        ], $override));
    }

    private function fakeRefreshResponse(): void
    {
        Http::fake([
            'auth.lazada.com/rest/auth/token/refresh*' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 25200,
                'refresh_expires_in' => 2592000,
                'code' => '0',
            ], 200),
        ]);
    }

    public function test_stores_requires_auth(): void
    {
        $this->getJson('/api/v1/lazada/stores')->assertStatus(401);
    }

    public function test_index_lists_only_lazada_shops(): void
    {
        $this->makeShop();

        $tiktok = Channel::create(['code' => 'tiktok', 'name' => 'TikTok Shop', 'is_active' => true]);
        ChannelShop::create([
            'channel_id' => $tiktok->id, 'shop_id' => 'TT-1', 'shop_name' => 'Toko TikTok', 'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/lazada/stores');

        $response->assertStatus(200)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.shop_name', 'Toko Lazada')
            ->assertJsonPath('data.0.token_status', 'active');
    }

    public function test_show_returns_detail_with_token_status(): void
    {
        $shop = $this->makeShop(['token_expires_at' => now()->subHour()]);

        $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/lazada/stores/{$shop->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.token_status', 'expired')
            ->assertJsonPath('data.shop_id', $shop->shop_id);
    }

    public function test_destroy_disconnects_store(): void
    {
        $shop = $this->makeShop();

        $this->actingAs($this->user, 'sanctum')->deleteJson("/api/v1/lazada/stores/{$shop->id}")
            ->assertStatus(200);

        $shop->refresh();
        $this->assertFalse($shop->is_active);
        $this->assertNull($shop->access_token);
        $this->assertNull($shop->refresh_token);
    }

    public function test_non_uuid_store_id_returns_404_not_500(): void
    {
        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/lazada/stores/not-a-uuid')
            ->assertStatus(404);
    }

    public function test_refresh_token_updates_shop(): void
    {
        $this->fakeRefreshResponse();
        $shop = $this->makeShop();

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/lazada/stores/{$shop->id}/refresh-token")
            ->assertStatus(200)
            ->assertJsonPath('data.shop_id', $shop->shop_id);

        $shop->refresh();
        $this->assertEquals('new-access-token', $shop->access_token);
        $this->assertEquals('new-refresh-token', $shop->refresh_token);
    }

    public function test_refresh_without_refresh_token_returns_422(): void
    {
        $shop = $this->makeShop(['refresh_token' => null]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/lazada/stores/{$shop->id}/refresh-token")
            ->assertStatus(422);
    }

    public function test_command_refreshes_only_expiring_tokens(): void
    {
        $this->fakeRefreshResponse();

        $expiring = $this->makeShop(['token_expires_at' => now()->addHours(12)]);
        $healthy = $this->makeShop(['token_expires_at' => now()->addDays(6)]);

        $this->artisan('lazada:refresh-tokens')
            ->expectsOutputToContain('1 diperbarui, 0 gagal, 1 dilewati')
            ->assertSuccessful();

        $this->assertEquals('new-access-token', $expiring->refresh()->access_token);
        $this->assertEquals('access-token', $healthy->refresh()->access_token);
    }

    public function test_request_success_returns_data(): void
    {
        Http::fake([
            'api.lazada.co.id/rest/seller/get*' => Http::response(['code' => '0', 'data' => ['name' => 'Toko']], 200),
        ]);

        $data = (new LazadaClient())->request('GET', '/seller/get', [], 'token-x');

        $this->assertEquals('Toko', $data['data']['name']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'access_token=token-x')
                && str_contains($request->url(), 'sign=');
        });
    }

    public function test_request_token_error_throws_token_expired(): void
    {
        Http::fake([
            'api.lazada.co.id/*' => Http::response(['code' => 'IllegalAccessToken', 'message' => 'token expired'], 200),
        ]);

        $this->expectException(TokenExpiredException::class);

        (new LazadaClient())->request('GET', '/seller/get', [], 'stale-token');
    }

    public function test_request_business_error_throws_exception(): void
    {
        Http::fake([
            'api.lazada.co.id/*' => Http::response(['code' => '500', 'message' => 'internal'], 200),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Lazada API Error');

        (new LazadaClient())->request('GET', '/seller/get');
    }

    public function test_adapter_factory_resolves_lazada(): void
    {
        $adapter = (new AdapterFactory())->make('lazada');

        $this->assertInstanceOf(LazadaAdapter::class, $adapter);
        $this->assertEquals('lazada', $adapter->getChannelCode());
    }

    public function test_adapter_api_error_fails_gracefully_without_throwing(): void
    {
        Http::fake([
            'api.lazada.co.id/*' => Http::response(['code' => 'ApiCallLimit', 'message' => 'too many requests'], 200),
        ]);

        $adapter = app(LazadaAdapter::class);
        $shop = $this->makeShop();
        $product = new \Modules\Product\Models\Product();
        $product->setRelation('variants', collect());
        $product->setRelation('media', collect());

        $result = $adapter->pushProduct($product, $shop);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('too many requests', $result['message']);
    }
}
