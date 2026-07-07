<?php

namespace Modules\Outbound\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Outbound\Models\Courier;

/**
 * Production-safe seeder untuk master data Courier (kurir/ekspedisi).
 *
 * Karakteristik:
 * - Idempotent: aman dijalankan berkali-kali. Baris yang sudah ada (match by code)
 *   tidak akan disentuh; baris baru diinsert.
 * - Global (tenant_id = null): kurir muncul di list global. Untuk skema per-tenant,
 *   duplikasi record per tenant harus dilakukan dengan seeder lain.
 * - Kode di-generate deterministik dari nama (slug uppercase). Bentrok
 *   diselesaikan dengan suffix `_2`, `_3`, dst.
 * - Daftar nama dibaca dari file JSON `database/seeders/data/couriers.json`
 *   (persis 141 kurir kanonik bentuk Jubelio). SATU sumber kebenaran, dipakai
 *   juga oleh `couriers:sync-master`. Untuk ubah master: edit JSON itu (tinggal
 *   paste array {courier_id, courier_name}) lalu seed / `couriers:sync-master`.
 *   Karena tetap, seed di environment baru tidak lagi menghasilkan daftar kurir
 *   yang pecah/berlebihan (lihat PLANNING-KONSOLIDASI-KURIR.md).
 *
 * Cara jalan (production):
 *   php artisan module:seed Outbound --class=CourierSeeder
 * atau via DB seeder root:
 *   php artisan db:seed --class="Modules\\Outbound\\Database\\Seeders\\CourierSeeder"
 */
class CourierSeeder extends Seeder
{
    public function run(): void
    {
        $names = self::canonicalNames();

        DB::transaction(function () use ($names) {
            foreach ($names as $name) {
                $normalized = trim($name);
                if ($normalized === '') {
                    continue;
                }

                $code = self::uniqueCodeFor($normalized);

                Courier::firstOrCreate(
                    ['code' => $code],
                    [
                        'name'                     => $normalized,
                        'tracking_url'             => null,
                        'logo_url'                 => null,
                        'is_active'                => true,
                        'supported_shipment_types' => null,
                        'metadata'                 => null,
                        'tenant_id'                => null,
                    ]
                );
            }
        });
    }

    public static function uniqueCodeFor(string $name): string
    {
        $base = self::makeCode($name);
        $code = $base;
        $i = 2;

        while (
            Courier::where('code', $code)
                ->where('name', '!=', $name)
                ->exists()
        ) {
            $code = $base . '_' . $i;
            $i++;
        }

        return $code;
    }

    public static function makeCode(string $name): string
    {
        $slug = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($slug === false || $slug === '') {
            $slug = $name;
        }

        $slug = strtoupper($slug);
        $slug = preg_replace('/[^A-Z0-9]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');

        if ($slug === '') {
            $slug = 'COURIER_' . strtoupper(substr(md5($name), 0, 8));
        }

        if (strlen($slug) > 120) {
            $slug = substr($slug, 0, 100) . '_' . strtoupper(substr(md5($name), 0, 8));
        }

        return $slug;
    }

    /**
     * Daftar kurir kanonik — SATU sumber kebenaran untuk seeder & command
     * `couriers:sync-master`. Persis daftar yang diminta (bentuk Jubelio).
     *
     * @return string[]
     */
    public static function canonicalNames(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        // Daftar kanonik dibaca dari file JSON (bentuk yang dikirim user:
        // array objek {courier_id, courier_name}). Untuk mengubah master kurir,
        // cukup edit database/seeders/data/couriers.json lalu jalankan ulang
        // seeder atau `php artisan couriers:sync-master --apply`.
        $path = __DIR__ . '/data/couriers.json';
        $raw = @file_get_contents($path);
        $rows = ($raw === false) ? [] : (json_decode($raw, true) ?: []);

        $names = [];
        $seen = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['courier_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                continue; // buang duplikat case-insensitive
            }
            $seen[$key] = true;
            $names[] = $name;
        }

        return $cache = $names;
    }
}
