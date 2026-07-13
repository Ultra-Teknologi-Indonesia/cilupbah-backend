<?php

namespace Modules\Sales\Services\Adapters;

use Modules\Sales\Models\SalesOrder;

/**
 * Kontrak per-kanal untuk mengambil label pengiriman.
 * Return array harus konsisten dengan output SalesOrderService::getShippingLabel()
 * agar bisa diteruskan langsung ke BulkShippingLabelService.
 *
 * Bentuk return yang dikenali BulkShippingLabelService:
 *  - ['type' => 'url', 'doc_url' => 'https://...']
 *  - ['type' => 'blob', 'bytes' => '<binary>']
 *  - ['status' => 'ready' | 'preparing' | 'failed' | 'self_design_required', 'data' => 'base64...']
 *
 * Adapter yang belum siap boleh melempar ChannelUnsupportedException dengan reason spesifik.
 */
interface ChannelLabelAdapter
{
    /**
     * @param  SalesOrder  $order
     * @param  array{document_type?: string, document_size?: string}  $options
     * @return array
     * @throws ChannelUnsupportedException
     */
    public function fetchLabel(SalesOrder $order, array $options): array;

    /**
     * Kanal yang di-handle adapter ini, mis. 'shopee', 'tiktok', 'lazada', 'woocommerce'.
     */
    public function channel(): string;
}
