<?php

namespace Modules\Order\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use Modules\Order\Repositories\OrderRepository;

class OrderService
{
    protected OrderRepository $orderRepository;

    public function __construct(OrderRepository $orderRepository)
    {
        $this->orderRepository = $orderRepository;
    }

    public function getPaginatedOrders()
    {
        return $this->orderRepository->getPaginatedOrders();
    }

    public function getOrderById($id)
    {
        return $this->orderRepository->getOrderById($id);
    }

    public function upsertFromChannel(array $orderData): ?int
    {
        try {
            DB::beginTransaction();

            $order = $this->orderRepository->upsertOrderBySalesOrderNo($orderData['salesorder_no'], $orderData);

            if (isset($orderData['items']) && is_array($orderData['items'])) {
                $this->orderRepository->syncOrderItems($order->id, $orderData['items']);
            }

            DB::commit();
            return $order->id;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to upsert order: " . $e->getMessage());
            throw $e;
        }
    }
}
