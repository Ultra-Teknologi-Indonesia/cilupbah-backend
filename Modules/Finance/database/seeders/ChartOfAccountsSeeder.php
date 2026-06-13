<?php

namespace Modules\Finance\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Finance\Models\Account;

class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['account_code' => '1-1000', 'account_name' => 'Kas', 'account_type' => 'asset'],
            ['account_code' => '1-1001', 'account_name' => 'Bank', 'account_type' => 'asset'],
            ['account_code' => '1-1002', 'account_name' => 'Giro', 'account_type' => 'asset'],
            ['account_code' => '1-1100', 'account_name' => 'Piutang Usaha', 'account_type' => 'asset'],
            ['account_code' => '1-1200', 'account_name' => 'Persediaan Barang', 'account_type' => 'asset'],
            ['account_code' => '2-2000', 'account_name' => 'Hutang Usaha', 'account_type' => 'liability'],
            ['account_code' => '3-3000', 'account_name' => 'Modal', 'account_type' => 'equity'],
            ['account_code' => '4-4000', 'account_name' => 'Pendapatan Penjualan', 'account_type' => 'revenue'],
            ['account_code' => '4-4100', 'account_name' => 'Diskon Penjualan', 'account_type' => 'revenue'],
            ['account_code' => '4-4200', 'account_name' => 'Retur Penjualan', 'account_type' => 'revenue'],
            ['account_code' => '5-5000', 'account_name' => 'Harga Pokok Penjualan', 'account_type' => 'expense'],
            ['account_code' => '6-6000', 'account_name' => 'Beban Operasional', 'account_type' => 'expense'],
            ['account_code' => '6-6100', 'account_name' => 'Beban Penyesuaian Persediaan', 'account_type' => 'expense'],
        ];

        foreach ($accounts as $account) {
            Account::firstOrCreate(
                ['account_code' => $account['account_code']],
                $account + ['is_active' => true]
            );
        }
    }
}
