<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            // Tipe kirim kanonik (REGULAR/EXPRESS/INSTANT/CARGO) yang diturunkan
            // sekali dari kurir saat order masuk, agar tidak ditebak-ulang & bisa
            // dipakai laporan/filter. courier_id (FK master) sudah ada sebelumnya.
            if (! Schema::hasColumn('sales_orders', 'resolved_shipment_type')) {
                $table->string('resolved_shipment_type', 20)->nullable()->after('shipping_type');
                $table->index('resolved_shipment_type', 'idx_so_resolved_shipment_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            if (Schema::hasColumn('sales_orders', 'resolved_shipment_type')) {
                $table->dropIndex('idx_so_resolved_shipment_type');
                $table->dropColumn('resolved_shipment_type');
            }
        });
    }
};
