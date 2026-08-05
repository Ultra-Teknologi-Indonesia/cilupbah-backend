<?php

namespace App\Support;

/**
 * Ubah pesan error teknis/mentah menjadi teks yang bisa dibaca pengguna.
 * Untuk error channel gunakan Modules\Channel\Support\UploadErrorPresenter;
 * helper ini untuk error non-channel (impor, PDF, webhook, umum).
 * Nilai mentah tetap disimpan di DB & log — helper ini hanya untuk yang DITAMPILKAN.
 */
class FriendlyError
{
    protected const TECH_MARKERS = [
        'exception', 'sqlstate', 'stack trace', 'undefined', 'null given',
        '::', 'fatal error', 'syntax error', 'array to string', 'call to a member',
        'curl', 'connection refused', 'deadlock', 'integrity constraint',
        'trait ', 'unexpected token', 'in /', 'on line', '\\',
    ];

    protected const IMPORT_FRIENDLY_MARKERS = [
        'baris', 'kolom', 'wajib', 'tidak valid', 'tidak boleh', 'format',
        'duplikat', 'sudah ada', 'kosong', 'tidak ditemukan', 'melebihi', 'header',
    ];

    public static function import(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $lower = mb_strtolower($raw);
        foreach (self::IMPORT_FRIENDLY_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return $raw;
            }
        }

        return 'Gagal memproses file. Periksa format dan isi file lalu coba lagi.';
    }

    public static function generic(?string $raw, string $fallback): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return $fallback;
        }

        return self::looksTechnical($raw) ? $fallback : $raw;
    }

    public static function looksTechnical(string $raw): bool
    {
        $lower = mb_strtolower($raw);
        foreach (self::TECH_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
    }
}
