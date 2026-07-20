<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fitur pembatalan PO dicabut: PO kini dihapus, tidak dibatalkan.
 *
 * Dua himpunan baris warisan harus dibereskan supaya tidak meledak saat dibaca:
 *
 * 1. purchase_orders berstatus CANCELLED -- status itu sudah tidak ada di
 *    PurchaseOrder::STATUSES. Dikembalikan ke DRAFT supaya tetap tampil dan
 *    bisa dihapus lewat alur baru.
 * 2. purchase_order_activities beraksi CANCELLED -- kolom action di-cast ke
 *    enum PurchaseActivityAction yang case CANCELLED-nya sudah dihapus, jadi
 *    baris lama akan melempar ValueError begitu dihidrasi. Dipetakan ke
 *    STATUS_CHANGED yang maknanya paling dekat.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('purchase_orders')
            ->where('status', 'CANCELLED')
            ->update(['status' => 'DRAFT']);

        DB::table('purchase_order_activities')
            ->where('action', 'CANCELLED')
            ->update(['action' => 'STATUS_CHANGED', 'action_id' => '900']);
    }

    public function down(): void
    {
        // Tidak reversibel: PO yang dulu CANCELLED sudah melebur dengan DRAFT
        // yang memang selalu ada, jadi keduanya tak bisa dipisahkan lagi.
    }
};
