<?php

namespace Modules\Channel\Tests\Unit;

use Mockery;
use Modules\Channel\Repositories\ChannelProductRepository;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\ShopeeAuthService;
use Modules\Channel\Services\ShopeeClient;
use Modules\Channel\Services\ShopeeProductService;
use PHPUnit\Framework\TestCase;

class ShopeeProductServiceBoostTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_boost_item_uses_success_and_failure_lists(): void
    {
        $client = Mockery::mock(ShopeeClient::class);
        $client->shouldReceive('request')
            ->once()
            ->with('POST', '/api/v2/product/boost_item', ['item_id_list' => [123, 456]], 'token', 'SHOP')
            ->andReturn([
                'response' => [
                    'success_list' => ['item_id_list' => [123]],
                    'failure_list' => [[
                        'item_id' => 456,
                        'failed_reason' => 'can not boost item repeatedly',
                    ]],
                ],
            ]);

        $service = $this->makeService($client);
        $result = $service->boostItem('SHOP', ['123', '456']);

        $this->assertTrue($result['123']['success']);
        $this->assertFalse($result['456']['success']);
        $this->assertStringContainsString('baru saja dinaikkan', $result['456']['reason']);
    }

    public function test_boost_item_does_not_send_invalid_ids_and_marks_missing_results(): void
    {
        $client = Mockery::mock(ShopeeClient::class);
        $client->shouldReceive('request')
            ->once()
            ->with('POST', '/api/v2/product/boost_item', ['item_id_list' => [789]], 'token', 'SHOP')
            ->andReturn(['response' => []]);

        $service = $this->makeService($client);
        $result = $service->boostItem('SHOP', ['not-an-id', '789']);

        $this->assertFalse($result['not-an-id']['success']);
        $this->assertStringContainsString('ID produk Shopee tidak valid', $result['not-an-id']['reason']);
        $this->assertFalse($result['789']['success']);
        $this->assertStringContainsString('tidak mengembalikan hasil', $result['789']['reason']);
    }

    private function makeService(ShopeeClient $client): ShopeeProductService
    {
        $shopRepository = Mockery::mock(ChannelShopRepository::class);
        $shopRepository->shouldReceive('findByShopId')
            ->with('SHOP')
            ->andReturn((object) [
                'id' => 'channel-shop-id',
                'shop_id' => 'SHOP',
                'access_token' => 'token',
            ]);

        return new ShopeeProductService(
            $client,
            $shopRepository,
            Mockery::mock(ShopeeAuthService::class),
            Mockery::mock(ChannelProductRepository::class),
        );
    }
}
