<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'owner' => 'Bertanggung jawab atas seluruh sistem dan memiliki otorisasi penuh tanpa batas.',
            'admin' => 'Bertanggung jawab atas pengelolaan administrasi keseluruhan sistem aplikasi.',
            'purchasing' => 'Bertanggung jawab untuk melakukan pembelian stok barang dan negosiasi dengan supplier.',
            'warehouse' => 'Bertanggung jawab atas pengelolaan operasional harian di dalam gudang.',
            'picker' => 'Bertanggung jawab untuk mengambil barang dari rak penyimpanan sesuai dengan detail pesanan.',
            'checker' => 'Bertanggung jawab untuk memeriksa kualitas, kuantitas, dan kesesuaian barang sebelum dipacking.',
            'handover' => 'Bertanggung jawab untuk menyerahkan paket pesanan yang sudah dikemas kepada pihak kurir/ekspedisi.',
            'shipper' => 'Bertanggung jawab untuk memasukkan pesanan ke pengiriman dan memastikan paket diserahkan ke kurir/ekspedisi sesuai jadwal.',
            'cs marketplace' => 'Bertanggung jawab untuk melayani pertanyaan, membalas chat, dan menangani komplain pelanggan di marketplace.',
            'putaway' => 'Bertanggung jawab untuk menyimpan dan memindahkan barang yang baru datang ke rak penyimpanan yang tepat.',
            'kepala gudang' => 'Bertanggung jawab penuh untuk memimpin, mengawasi, dan memastikan efisiensi seluruh aktivitas gudang.',
            'leader outbound' => 'Bertanggung jawab untuk mengoordinasikan tim dalam proses pemenuhan dan pengeluaran barang (pesanan).',
            'leader inbound' => 'Bertanggung jawab untuk mengoordinasikan tim dalam proses penerimaan dan pengecekan barang masuk.'
        ];

        foreach ($roles as $role => $description) {
            $roleModel = Role::firstOrCreate(['name' => strtolower($role), 'guard_name' => 'web']);
            $roleModel->description = $description;
            $roleModel->save();
        }

        $permissions = [
            'view-user-history',
            'view-user',
            'create-user',
            'edit-user',
            'delete-user',
            'export-user',
            'view-dashboard',
            'force-logout-user',
            'view-role',
            'create-role',
            'edit-role',
            'delete-role',
            'view-permission',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }
}
