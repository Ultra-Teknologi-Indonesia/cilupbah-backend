<?php

/**
 * Kamus nilai valid untuk filter Spatie yang value-nya tidak bisa di-detect
 * secara otomatis dari FormRequest rules.
 *
 * Key: nama filter (case-sensitive). Value: array nilai valid.
 * Bila ada konteks modul, key bisa berbentuk "modul.filter" (mis. "sales.status").
 */

return [
    // Sales orders
    'sales.status' => [
        'new', 'pending', 'ready', 'reserved', 'picking', 'packing',
        'shipping', 'delivered', 'done', 'cancelled', 'returned',
    ],
    'sales.channel' => [
        'shopee', 'lazada', 'tiktok', 'woocommerce', 'manual', 'offline', 'pos',
    ],
    'sales.decision' => [
        'accepted', 'rejected', 'pending',
    ],
    'sales.contact_status' => [
        'contacted', 'not_contacted', 'follow_up',
    ],
    'sales.payment' => [
        'paid', 'unpaid', 'partial', 'cod',
    ],
    'sales.label_printed' => [
        'printed', 'not_printed',
    ],
    'sales.content_type' => [
        'physical', 'digital', 'service',
    ],

    // Returns
    'sales.return_status' => [
        'requested', 'approved', 'rejected', 'received', 'resolved', 'closed',
    ],
    'sales.dispute_outcome' => [
        'no_return_needed', 'seller_win', 'seller_refuse_return', 'buyer_win', 'refunded',
    ],

    // Purchase
    'purchase.status' => [
        'draft', 'submitted', 'approved', 'received', 'closed', 'cancelled',
    ],

    // Inventory / Transfer
    'inventory.transfer_status' => [
        'draft', 'approved', 'in_transit', 'received', 'cancelled',
    ],
    'inventory.bin_transfer_status' => [
        'new', 'in_progress', 'done',
    ],
    'inventory.adjustment_status' => [
        'draft', 'submitted', 'approved',
    ],

    // Inbound
    'inbound.status' => [
        'pending', 'partial', 'received', 'putaway_pending', 'complete', 'cancelled',
    ],

    // Outbound
    'outbound.picking_status'  => ['not_started', 'in_progress', 'completed'],
    'outbound.packing_status'  => ['not_started', 'in_progress', 'completed'],
    'outbound.shipping_status' => ['not_shipped', 'label_printed', 'picked_up', 'in_transit', 'delivered', 'returned'],

    // Product
    'product.status' => [
        'active', 'inactive', 'archived',
    ],
    'product.sync_status' => [
        'synced', 'pending', 'failed', 'not_mapped',
    ],

    // Channel
    'channel.code' => ['shopee', 'lazada', 'tiktok', 'woocommerce'],
    'channel.connection_status' => ['connected', 'disconnected', 'expired'],

    // Auth
    'auth.user_status' => ['active', 'inactive', 'suspended'],

    // Delivery / shipping
    'delivery_method' => ['COURIER', 'SELF_PICKUP', 'JUBELIO_SHIPMENT', 'INSTANT'],

    // Notification
    'notification.channel' => ['in_app', 'wa', 'email'],
    'notification.read_status' => ['read', 'unread'],
];
