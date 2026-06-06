<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

use App\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            'Merchandiser' => 'Orang yang bertanggung jawab mencatatkan pembelian langsung (biasanya pembelian reseller, B2B, dll)',
            'Customer Service' => 'Orang yang berhubungan dengan customer, membalas chat, mengedit pesanan sesuai permintaan customer',
            'Warehouse Staff' => 'Orang yang bekerja di gudang. Menempatkan barang, pick dan pack dan melakukan stock opname',
            'Bookkeeper' => 'Orang yang bertanggung jawab di pembukuan (akunting) perusaan',
            'Purchasing' => 'Orang yang bertanggung jawab di proses pembelian dan kontak dengan vendor',
            'Sales' => 'Orang yang bertanggung jawab mencatatkan pembelian langsung (Biasanya pembelian reseller, B2B, dll)',
            'POS Cashier' => 'Kasir outlet toko Fisik',
            'Store Manager' => 'Orang yang bertanggung jawab terhadap operasional toko fisik',
            'Supervisior' => 'Orang yang bertanggung jawab terhadap operasional Jubelio sehari-hari',
            'Administrator' => 'Orang yang bertanggung jawab terhadap seluruh aspek aplikasi Jubelio kecuali billing',
            'Owner' => 'Owner dari akun Jubelio',
            'Auditor' => 'Orang yang ditunjuk untuk memeriksa operasional Jubelio'
        ];

        foreach ($roles as $role => $description) {
            $roleModel = Role::firstOrCreate(['name' => strtolower($role), 'guard_name' => 'web']);
            $roleModel->description = $description;
            $roleModel->save();
        }

        $permissions = [
            'view-user-history',
            'view-dashboard',
            'force-logout-user',
            'view-role',
            'create-role',
            'edit-role',
            'delete-role',
            'view-permission',
        ];

        foreach ($permissions as $permission) {
            \App\Models\Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }
}
