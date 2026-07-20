<?php

/*
 * Sengaja kosong.
 *
 * Sebelumnya file ini berisi `Route::resource(...)` hasil scaffolding generator
 * nwidart/laravel-modules. Baris itu terdaftar di URI polos tanpa prefix
 * (mis. `DELETE /taxes/{id}`) dan PSR-4 me-resolve-nya ke controller API asli
 * di `app/Http/Controllers/` — sehingga mengekspos create/update/delete TANPA
 * permission gate, menduplikasi route `api/v1` yang sudah ter-gate.
 *
 * Lihat PLANNING-RBAC-MAPPING-GAP.md (T2). Semua endpoint modul ini
 * didefinisikan di routes/api.php lengkap dengan middleware permission.
 */
