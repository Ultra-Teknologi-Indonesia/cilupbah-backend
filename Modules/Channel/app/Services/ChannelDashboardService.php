<?php

namespace Modules\Channel\Services;

use Modules\Channel\Repositories\ChannelOrderRepository;
use Modules\Channel\Repositories\ChannelProductRepository;

class ChannelDashboardService
{
    protected ChannelOrderRepository $orderRepository;
    protected ChannelProductRepository $productRepository;

    public function __construct(ChannelOrderRepository $orderRepository, ChannelProductRepository $productRepository)
    {
        $this->orderRepository = $orderRepository;
        $this->productRepository = $productRepository;
    }

    public function getDashboardData(): array
    {
        $products = $this->productRepository->getAllProducts();
        $orders = $this->orderRepository->getAllOrders();
        
        foreach ($orders as $order) {
            $items = $this->orderRepository->getOrderItems($order->id);
            $itemNames = [];
            foreach ($items as $item) {
                $variant = $this->productRepository->getVariantBySku($item->sku);
                if ($variant) {
                    $product = $this->productRepository->findById($variant->product_id);
                    $itemNames[] = $product ? $product->name : $item->sku;
                } else {
                    $itemNames[] = $item->sku ?: 'Unknown Item';
                }
            }
            $order->product_names = implode(', ', $itemNames);
        }

        return ['products' => $products, 'orders' => $orders];
    }
}
