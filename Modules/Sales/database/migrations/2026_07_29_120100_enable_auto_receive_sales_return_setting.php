<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Aktifkan auto-receive retur: retur yang disetujui langsung ter-receive pada approved_qty
     * sehingga dokumen penerimaan retur langsung bisa di-Penempatan (putaway) tanpa terima manual.
     * Global; bisa dimatikan lagi lewat toggle Pengaturan Retur.
     */
    public function up(): void
    {
        DB::table('sales_return_settings')->update(['auto_receive' => true]);
    }

    public function down(): void
    {
        DB::table('sales_return_settings')->update(['auto_receive' => false]);
    }
};
