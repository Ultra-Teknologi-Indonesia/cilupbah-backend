return (function (): array {
    $shopId = 'DRY-RUN-SHOP';

    $shopee = \Mockery::mock(\Modules\Channel\Services\ShopeeOrderService::class);
    $shopee->shouldReceive('handleBuyerCancellation')
        ->once()
        ->with($shopId, 'DRY-SHOPEE-ORDER', 'ACCEPT')
        ->andReturn(['handled' => true, 'operation' => 'ACCEPT']);

    $tiktok = \Mockery::mock(\Modules\Channel\Services\TikTokOrderService::class);
    $tiktok->shouldReceive('acceptBuyerCancellation')
        ->once()
        ->with($shopId, 'DRY-TIKTOK-ORDER')
        ->andReturn(['success' => true]);

    $lazada = \Mockery::mock(\Modules\Channel\Services\LazadaOrderService::class);
    $lazada->shouldReceive('respondBuyerCancellation')
        ->once()
        ->with($shopId, 'DRY-LAZADA-ORDER', \Modules\Sales\Jobs\RespondBuyerCancellationJob::ACCEPT, null, null)
        ->andReturn(['handled' => true, 'decision' => \Modules\Sales\Jobs\RespondBuyerCancellationJob::ACCEPT]);

    app()->instance(\Modules\Channel\Services\ShopeeOrderService::class, $shopee);
    app()->instance(\Modules\Channel\Services\TikTokOrderService::class, $tiktok);
    app()->instance(\Modules\Channel\Services\LazadaOrderService::class, $lazada);

    $orders = [
        new \Modules\Sales\Models\SalesOrder(['source' => 'shopee', 'channel_shop_id' => $shopId, 'channel_order_no' => 'DRY-SHOPEE-ORDER', 'salesorder_no' => 'DRY-SHOPEE-ORDER']),
        new \Modules\Sales\Models\SalesOrder(['source' => 'tiktok', 'channel_shop_id' => $shopId, 'channel_order_no' => 'DRY-TIKTOK-ORDER', 'salesorder_no' => 'DRY-TIKTOK-ORDER']),
        new \Modules\Sales\Models\SalesOrder(['source' => 'lazada', 'channel_shop_id' => $shopId, 'channel_order_no' => 'DRY-LAZADA-ORDER', 'salesorder_no' => 'DRY-LAZADA-ORDER']),
    ];

    $results = [];
    foreach ($orders as $order) {
        $results[] = (new \Modules\Sales\Jobs\RespondBuyerCancellationJob($order->salesorder_no, \Modules\Sales\Jobs\RespondBuyerCancellationJob::ACCEPT))
            ->simulate($order);
    }

    return $results;
})();
