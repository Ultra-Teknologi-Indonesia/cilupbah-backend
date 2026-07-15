<?php

namespace Modules\Outbound\Contracts;

use Modules\Sales\Models\SalesOrder;

interface MarketPlaceLogisticsInterface
{
    /**
     * Panggil driver kurir instan untuk daftar order dari channel yang di-support adapter.
     *
     * @param  array<int,string>  $orderIds  UUID SalesOrder lokal
     */
    public function callDriver(array $orderIds, int $shipperId): DriverCallResult;

    /**
     * Panggil ulang driver saat status = failed / pickup_retry.
     * Untuk Shopee memakai endpoint terpisah (update_shipping_order).
     * Untuk TikTok/Lazada = idempotent re-fire callDriver.
     *
     * @param  array<int,string>  $orderIds
     */
    public function retryCallDriver(array $orderIds, int $shipperId): DriverCallResult;

    /**
     * Ambil status tracking terkini dari channel untuk 1 order.
     * Digunakan job RefreshInstantTrackingJob dan tombol refresh manual.
     */
    public function getTrackingStatus(string $orderId): array;

    /**
     * Dispatcher RTS ke marketplace untuk 1 order.
     * Return: ['status' => 'success'|'failed'|'queued'|'skipped', 'message' => string].
     * WC adapter mengembalikan 'skipped' (tandai lokal saja, tidak ada API).
     */
    public function readyToShip(SalesOrder $order): array;
}
