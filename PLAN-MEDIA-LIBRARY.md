# PLAN — Spatie Media Library + Cloudflare R2 (reusable add/replace/delete)

## Konteks
- `spatie/laravel-medialibrary ^11.23` & `league/flysystem-aws-s3-v3` **sudah terinstall** di vendor, tapi belum di-setup (config belum dipublish, migration `media` belum ada, belum ada model pakai media).
- Disk `s3` di `config/filesystems.php` **sudah** memetakan env `AWS_*` → ini dipakai untuk **Cloudflare R2** (endpoint + path-style + custom `AWS_URL=https://assets.ultra-fit.id`).
- ⚠️ Semua model pakai `HasUuid7` (id UUID). Migration `media` Spatie default `$table->morphs('model')` = **bigint** → WAJIB diubah ke `uuidMorphs` agar bisa attach ke model UUID.
- `Modules/Product/MediaController@upload` masih stub (TODO) — tidak diutak-atik agar tidak bentrok domain Rasyid.

## Langkah
1. **Publish** migration + config media-library; ubah `morphs('model')` → `uuidMorphs('model')`; `migrate`.
2. **Config R2**: `config/media-library.php` `disk_name` default → `env('MEDIA_DISK', 's3')`. Tambah `MEDIA_DISK=s3` ke `.env.example`.
3. **Reusable `App\Services\MediaService`** (generic, untuk semua model `HasMedia`, dibungkus try/catch — no-500):
   - `add(HasMedia $model, UploadedFile $file, string $collection = 'default', array $props = []): Media`
   - `replace(HasMedia $model, UploadedFile $file, string $collection = 'default', array $props = []): Media` — untuk koleksi single-file: bersihkan lalu tambah.
   - `delete(HasMedia $model, string $collection = 'default'): void` — hapus seluruh isi koleksi.
   - `deleteById(HasMedia $model, int $mediaId): bool` — hapus 1 media milik model (cek kepemilikan → no orphan delete).
   - `url(HasMedia $model, string $collection = 'default', string $conversion = ''): ?string`
4. **Trait `App\Concerns\HasUploadableMedia`** — convenience: `use InteractsWithMedia` + default helper agar model cukup `implements HasMedia` lalu daftarkan koleksi.
5. **Integrasi contoh (low-risk)**: `User` → koleksi `avatar` (singleFile, replace-semantics). Membuktikan add/replace/delete end-to-end.
6. **Tests** (`Storage::fake` pada disk media): add membuat 1 media; replace mengganti (tetap 1, file lama hilang); delete mengosongkan; deleteById hanya hapus milik model; url benar. Pastikan tak ada error 500 saat file invalid → ditangani di layer validasi/Service.

## Tidak dilakukan (hindari konflik)
- Tidak mengganti `MediaController@upload` (domain Product/Rasyid).
- Tidak memaksa Spatie ke Product images (masih pakai JSON refs). Didokumentasikan cara pakainya.

## DoD
- [ ] Migration `media` (uuidMorphs) jalan
- [ ] MediaService add/replace/delete/deleteById/url + trait
- [ ] User avatar terintegrasi
- [ ] Test hijau (fake disk), no-500
- [ ] route:cache aman
