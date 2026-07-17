<?php

namespace Modules\Sales\Enums;

/**
 * Set kanonik lintas-channel setelah normalisasi.
 * Adapter Shopee/TikTok/Lazada/WooCommerce mengonversi kode marketplace
 * ke salah satu case di bawah sebelum simpan ke sales_orders.channel_status.
 */
enum ChannelStatus: string
{
    case UNPAID             = 'UNPAID';
    case READY_TO_SHIP      = 'READY_TO_SHIP';
    case PROCESSED          = 'PROCESSED';
    case SHIPPED            = 'SHIPPED';
    case TO_CONFIRM_RECEIVE = 'TO_CONFIRM_RECEIVE';
    case COMPLETED          = 'COMPLETED';
    case CANCELLED          = 'CANCELLED';
    case RETURN_REQUESTED   = 'RETURN_REQUESTED';
    case RETURNED           = 'RETURNED';
    case IN_CANCEL          = 'IN_CANCEL';
    case UNKNOWN            = 'UNKNOWN';
}
