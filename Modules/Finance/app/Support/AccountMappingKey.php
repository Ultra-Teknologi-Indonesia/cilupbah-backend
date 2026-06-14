<?php

namespace Modules\Finance\Support;

final class AccountMappingKey
{
    public const SALES_REVENUE = 'sales_revenue';
    public const ACCOUNTS_RECEIVABLE = 'accounts_receivable';
    public const INVENTORY = 'inventory';
    public const ACCOUNTS_PAYABLE = 'accounts_payable';
    public const SALES_RETURN = 'sales_return';
    public const COGS = 'cogs';

    public const DEFINITIONS = [
        self::SALES_REVENUE => ['label' => 'Pendapatan Penjualan', 'default' => '4-4000'],
        self::ACCOUNTS_RECEIVABLE => ['label' => 'Piutang Usaha', 'default' => '1-1100'],
        self::INVENTORY => ['label' => 'Persediaan Barang', 'default' => '1-1200'],
        self::ACCOUNTS_PAYABLE => ['label' => 'Hutang Usaha', 'default' => '2-2000'],
        self::SALES_RETURN => ['label' => 'Retur Penjualan', 'default' => '4-4200'],
        self::COGS => ['label' => 'Harga Pokok Penjualan', 'default' => '5-5000'],
    ];

    public static function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function isValid(string $key): bool
    {
        return array_key_exists($key, self::DEFINITIONS);
    }

    public static function label(string $key): ?string
    {
        return self::DEFINITIONS[$key]['label'] ?? null;
    }

    public static function defaultCode(string $key): ?string
    {
        return self::DEFINITIONS[$key]['default'] ?? null;
    }
}
