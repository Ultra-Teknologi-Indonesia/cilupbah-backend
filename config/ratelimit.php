<?php

return [

    // Penyimpanan penghitung throttle. Kosongkan (null) = pakai cache store default.
    // Di staging/production set 'redis' agar hitungan atomik, cepat, dan konsisten
    // antar-pod (bukan menulis ke database tiap request). Redis sudah dipakai Horizon.
    'store' => env('RATE_LIMIT_STORE') ?: null,

    // Endpoint login (tamu). Dikunci per-email + IP supaya banyak pekerja di satu
    // WiFi gudang tidak saling menghabiskan jatah. Backstop per-IP menahan
    // credential-stuffing (satu IP menyemprot banyak email).
    'login' => [
        'per_email' => (int) env('RATE_LIMIT_LOGIN_PER_EMAIL', 10),
        'per_ip' => (int) env('RATE_LIMIT_LOGIN_PER_IP', 60),
    ],

    // Lupa-password / OTP (tamu). Per-email + IP, per-aksi (send/verify/reset punya
    // ember terpisah lewat nama route).
    'forgot_password' => [
        'per_email' => (int) env('RATE_LIMIT_FORGOT_PER_EMAIL', 10),
        'per_ip' => (int) env('RATE_LIMIT_FORGOT_PER_IP', 60),
    ],

    // Jaring global seluruh API. Ter-autentikasi dikunci per identitas pengguna
    // (token), jadi WiFi gudang bersama tidak relevan. Tamu dikunci per-IP.
    'api' => [
        'per_identity' => (int) env('RATE_LIMIT_API_PER_IDENTITY', 300),
        'per_ip' => (int) env('RATE_LIMIT_API_PER_IP', 600),
    ],

    // Tier endpoint mahal (export, bulk, cetak label/resi massal, sync, download
    // channel, laporan besar). Pasang 'throttle:heavy' pada grup route terkait.
    'heavy' => [
        'per_identity' => (int) env('RATE_LIMIT_HEAVY_PER_IDENTITY', 30),
    ],

];
