<?php

namespace Modules\Outbound\Support;

/**
 * Membersihkan string kurir mentah dari marketplace menjadi nama kurir kanonik
 * yang bisa dicocokkan. Menangani pola nyata yang ditemukan di data:
 *
 *  - Lazada majemuk : "Drop-off: LEX ID, Delivery: J&T"  -> "J&T"  (ambil kurir pengantar/last-mile)
 *                     "Pickup: J&T CARGO, Delivery: J&T CARGO" -> "J&T CARGO"
 *  - TikTok virtual : "TT Virtual# JNT express"           -> "JNT express"
 *  - Shopee sandbox : "Sandbox-J&T Cargo(Don't modify)"   -> "J&T Cargo"
 *  - Penanda uji    : "Global Standard Shipping(Test)"    -> "Global Standard Shipping"
 *
 * Catatan: ini HANYA untuk pencocokan kode kurir (resolveCode) & tipe kurir.
 * Deteksi instan berbasis regex di InstantOrderClassifier sengaja tetap pada
 * string mentah agar konsisten dengan query SQL (`~*`) di repository.
 */
class CourierNameNormalizer
{
    public static function clean(?string $raw): string
    {
        $name = trim((string) $raw);
        if ($name === '') {
            return '';
        }

        // 1. String majemuk Lazada: kurir sebenarnya = bagian "Delivery:" (last-mile).
        //    Jika tak ada "Delivery:", pakai bagian "Drop-off:"/"Pickup:" yang ada.
        if (preg_match('/delivery\s*:\s*(.+)$/i', $name, $m)) {
            $name = trim($m[1]);
        } elseif (preg_match('/(?:drop-?off|pickup)\s*:\s*(.+)$/i', $name, $m)) {
            $name = trim($m[1]);
        }

        // 2. Buang awalan virtual/sandbox marketplace.
        $name = preg_replace('/^\s*TT\s*Virtual#\s*/i', '', $name);
        $name = preg_replace('/^\s*Sandbox-\s*/i', '', $name);

        // 3. Buang penanda uji di akhir: "(Don't modify)", "(Test)".
        $name = preg_replace('/\s*\((?:don\'?t modify|test)\)\s*$/i', '', $name);

        // 4. Rapikan sisa pemisah/spasi.
        return trim((string) $name, " \t,-");
    }
}
