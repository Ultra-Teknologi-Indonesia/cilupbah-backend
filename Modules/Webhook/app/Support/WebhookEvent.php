<?php

namespace Modules\Webhook\Support;

/**
 * Daftar 9 event webhook Jubelio yang didukung Cilupbah.
 */
final class WebhookEvent
{
    public const INVOICE = 'invoice';
    public const PAYMENT = 'payment';
    public const PRICE = 'price';
    public const PRODUCT = 'product';
    public const PURCHASE_ORDER = 'purchaseorder';
    public const SALES_ORDER = 'salesorder';
    public const SALES_RETURN = 'salesreturn';
    public const STOCK = 'stock';
    public const STOCK_TRANSFER = 'stocktransfer';

    public const ALL = [
        self::INVOICE,
        self::PAYMENT,
        self::PRICE,
        self::PRODUCT,
        self::PURCHASE_ORDER,
        self::SALES_ORDER,
        self::SALES_RETURN,
        self::STOCK,
        self::STOCK_TRANSFER,
    ];

    /** Nilai valid untuk kolom `event` subscription (termasuk wildcard). */
    public static function subscriptionValues(): array
    {
        return array_merge(self::ALL, ['*']);
    }
}
