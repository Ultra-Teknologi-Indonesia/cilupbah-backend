<?php

namespace Modules\Outbound\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Outbound\Models\Courier;

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

    public static function canonicalNames(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

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
                continue; 
            }
            $seen[$key] = true;
            $names[] = $name;
        }

        return $cache = $names;
    }
}
