<?php

namespace Modules\Purchase\Enums;

enum PurchaseActivityAction: string
{
    case CREATED          = 'CREATED';
    case FIELD_CHANGED    = 'FIELD_CHANGED';
    case ITEM_ADDED       = 'ITEM_ADDED';
    case ITEM_CHANGED     = 'ITEM_CHANGED';
    case ITEM_REMOVED     = 'ITEM_REMOVED';
    case RECEIPT_REVERSED = 'RECEIPT_REVERSED';
    case RECEIVED         = 'RECEIVED';
    case STATUS_CHANGED   = 'STATUS_CHANGED';

    public function code(): string
    {
        return match ($this) {
            self::CREATED          => '100',
            self::FIELD_CHANGED    => '200',
            self::ITEM_ADDED       => '300',
            self::ITEM_CHANGED     => '310',
            self::ITEM_REMOVED     => '320',
            self::RECEIPT_REVERSED => '400',
            self::RECEIVED         => '500',
            self::STATUS_CHANGED   => '900',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CREATED          => 'Pesanan dibuat',
            self::FIELD_CHANGED    => 'Data pesanan diubah',
            self::ITEM_ADDED       => 'Produk ditambahkan',
            self::ITEM_CHANGED     => 'Produk diubah',
            self::ITEM_REMOVED     => 'Produk dihapus',
            self::RECEIPT_REVERSED => 'Penerimaan ditarik balik',
            self::RECEIVED         => 'Barang diterima',
            self::STATUS_CHANGED   => 'Status berubah',
        };
    }
}
