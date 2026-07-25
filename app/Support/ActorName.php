<?php

namespace App\Support;

use App\Models\User;

/**
 * Menormalkan nilai "pelaku" (created_by / updated_by / approved_by / dsb) untuk
 * DITAMPILKAN sebagai nama. Sebagian alur menyimpan nama, sebagian menyimpan
 * UUID user atau format "user:UUID" (mis. ledger stok, koreksi otomatis).
 *
 * resolve():
 *   - "user:UUID" / "UUID"  → nama user (di-cache per-request)
 *   - "system" / nama biasa → dikembalikan apa adanya
 *   - null / kosong         → dikembalikan apa adanya
 */
class ActorName
{
    private static array $cache = [];

    public static function resolve(?string $actor): ?string
    {
        if ($actor === null || $actor === '') {
            return $actor;
        }

        $id = preg_replace('/^user:/', '', $actor);

        if (! self::isUuid($id)) {
            return $actor;
        }

        if (! array_key_exists($id, self::$cache)) {
            self::$cache[$id] = optional(User::find($id))->name;
        }

        return self::$cache[$id] ?? $actor;
    }

    private static function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value,
        );
    }
}
