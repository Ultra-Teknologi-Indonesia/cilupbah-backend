# PLAN — Bulk Import Produk (BE) — Production-Ready

> Status: **Planning** · Dibuat: 2026-06-20 · Scope: **Backend saja** (FE menyusul)
> Tujuan: menjadikan fitur import produk **satuan** & **bundle** layak produksi — template Excel ter-generate, feedback hasil/error per-baris, tahan skala besar (queue + chunk), tervalidasi, dan teruji.

---

## 1. Kondisi Sekarang & Masalah

Pipeline import sudah ada dan happy-path jalan, tetapi belum production-ready.

| Komponen | File | Catatan |
|---|---|---|
| Controller | `Modules/Product/app/Http/Controllers/ProductImportController.php` | importSingle/importBundle + downloadSingle/BundleTemplate |
| Service | `Modules/Product/app/Services/ProductImportService.php` | processSingleProductRow `:12`, processBundleRow `:46` |
| Repo | `Modules/Product/app/Repositories/ProductImportRepository.php` | upsertProductByName (match **by name** `:17`), upsertVariantBySku |
| Import (single) | `Modules/Product/app/Imports/ProductImport.php` → `ProductDataSheetImport.php` | `ToCollection` + `WithHeadingRow` (muat semua ke memori) |
| Import (bundle) | `Modules/Product/app/Imports/BundleImport.php` → `BundleDataSheetImport.php` | sda |
| Routes | `Modules/Product/routes/api.php:161-164` | template + import single/bundle |

### Gap yang harus ditutup
1. **Template hilang** — `downloadSingleTemplate`/`downloadBundleTemplate` membaca file statis `base_path('template_import_productv2.xlsx')` & `template_import_bundle.xlsx` yang **tidak ada** → 404.
2. **Gagal senyap** — error per-baris hanya `Log::error`; controller selalu balas `200 "berhasil"` walau semua baris gagal. Tak ada ringkasan sukses/gagal/alasan.
3. **Sinkron + tanpa chunk** — `Excel::import` dengan `ToCollection` memuat seluruh sheet ke memori dalam request → file besar timeout/OOM.
4. **Tanpa validasi** — tidak ada `WithValidation`; nilai salah (harga non-numerik, kolom wajib kosong) menelan error diam-diam.
5. **Match produk by name** — `updateOrCreate(['name' => $name])` rawan tabrakan (nama bukan kunci unik).
6. **Status produk hasil import** — `$data` tidak menyertakan `status` → ikut default kolom (kemungkinan bukan `master`). Perlu eksplisit & disepakati.
7. **Nol test.**

---

## 2. Keputusan Arsitektur

1. **Template di-generate dinamis** lewat kelas Export `maatwebsite/excel` (bukan file statis), agar header/contoh/petunjuk selalu sinkron dengan parser. Endapkan ke storage cache opsional.
2. **Import = asinkron, berbasis batch.** Upload file → simpan ke storage → buat record `product_import_batches` → dispatch job ber-queue. Mengikuti pola `download_transactions` (state + progress + counts).
3. **Chunk reading + batch insert** (`WithChunkReading`, `WithBatchInserts`) + `ShouldQueue` agar tahan ratusan-ribuan baris.
4. **Validasi per-baris** (`WithValidation`) + kumpulkan kegagalan via `SkipsOnFailure`/`SkipsOnError` → simpan ke `product_import_errors` (baris, kolom, pesan).
5. **Feedback** lewat endpoint status batch: `total`, `processed`, `success`, `failed`, daftar error per-baris, link unduh "error report".
6. **Dua mode tetap terpisah**: `type = single | bundle`. Satu tabel batch, satu jalur job, dua parser sheet (sudah ada `ProductDataSheetImport` & `BundleDataSheetImport`).
7. **Idempotensi**: single → upsert varian **by SKU** (`item_code`) sebagai kunci utama; produk dikelompokkan by `item_group_name` tetapi di-resolve ke produk yang konsisten (lihat §6). Bundle → upsert komposisi by (bundle_sku, component_sku).

---

## 3. Tabel Baru

### 3a. `product_import_batches` (grain: 1 upload)
Meniru `download_transactions`.

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | uuid v7 | PK |
| `batch_no` | string unique | nomor batch (mis. `IMP-20260620-xxxx`) |
| `type` | enum `single\|bundle` | mode import |
| `executed_by` | uuid null | FK users |
| `original_filename` | string | nama file asli |
| `stored_path` | string | path file di storage |
| `state` | enum `queued\|processing\|done\|done_with_errors\|failed` | status |
| `total_rows` | int | total baris terbaca (≥ heading) |
| `processed_rows` | int | sudah diproses |
| `success_rows` | int | sukses |
| `failed_rows` | int | gagal |
| `progress_percent` | int | 0–100 |
| `error_message` | text null | error fatal (mis. format file) |
| `created_at/updated_at` | ts | |

Index: `state`, `created_at`, `(type, state)`.

### 3b. `product_import_errors` (grain: 1 baris gagal)
| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | uuid v7 | PK |
| `import_batch_id` | uuid | FK → product_import_batches (cascade) |
| `row_number` | int | nomor baris di file (termasuk offset heading) |
| `attribute` | string null | kolom penyebab (mis. `sell_price`) |
| `message` | string | pesan error |
| `row_snapshot` | json null | isi baris untuk diagnosa |
| `created_at` | ts | |

Index: `import_batch_id`.

> Alternatif ringan: simpan error sebagai JSON di kolom `product_import_batches.errors`. Tabel terpisah dipilih agar bisa dipaginate & di-export tanpa memuat semua ke memori.

---

## 4. Kontrak API (BE)

| Method | Path | Fungsi |
|---|---|---|
| GET | `products/import/template/single` | unduh template single (generated) |
| GET | `products/import/template/bundle` | unduh template bundle (generated) |
| POST | `products/import/single` | upload → buat batch `single` → 202 + `batch_id` |
| POST | `products/import/bundle` | upload → buat batch `bundle` → 202 + `batch_id` |
| GET | `products/import/batches` | daftar batch (paginated, filter type/state) |
| GET | `products/import/batches/{id}` | detail batch (counts, progress, state) |
| GET | `products/import/batches/{id}/errors` | daftar error per-baris (paginated) |
| GET | `products/import/batches/{id}/errors/download` | unduh laporan error (xlsx) |

Catatan: endpoint upload berubah dari **sinkron 200** menjadi **202 Accepted** + `batch_id` (klien polling status). Pertahankan kompat respons `ApiResponse` (`success/data/message`).

---

## 5. Skema Kolom Template

### 5a. Single (`type=single`) — header = key parser saat ini
`item_category_id`, `category`, `brand`, `item_group_name` (nama produk, **wajib**), `item_code` (SKU varian, **wajib**), `barcode`, `sell_price` (**wajib, numerik ≥ 0**), `description`, `package_weight`, `package_length`, `package_width`, `package_height`, `image_url1`..`image_url5`, `default_images`.

> Multi-varian: beberapa baris dengan `item_group_name` sama + `item_code` berbeda = varian dari satu produk.

### 5b. Bundle (`type=bundle`)
`item_code` (SKU produk bundle, **wajib, harus sudah ada**), `sku_composition` (SKU komponen, **wajib, harus sudah ada**), `qty` (**wajib, integer ≥ 1**).

Template generated berisi: **sheet data** (header + 1–2 baris contoh) + **sheet "Petunjuk"** (penjelasan tiap kolom, mana wajib, format). Nama sheet data mengikuti yang dibaca parser: `Pengisian Import Produk` (single) & `Pengisian Data` (bundle).

---

## 6. Aturan Validasi & Pemrosesan

### Validasi (`WithValidation`, per baris)
- **Single:** `item_group_name` required|string; `item_code` required|string; `sell_price` required|numeric|min:0; `package_*` nullable|numeric|min:0; `image_url*`/`default_images` nullable|url; `item_category_id` nullable|integer.
- **Bundle:** `item_code` required|string; `sku_composition` required|string; `qty` required|integer|min:1.
- Baris kosong dilewati (`SkipsEmptyRows`).

### Pemrosesan
- **Single:** resolve kategori (by id→name→`Uncategorized`) & brand (find-or-create) — sudah ada (`:67-87`). Upsert produk + varian. **Perbaiki match produk**: gunakan kunci stabil (`external_group_code`/SKU grup) bila tersedia; bila hanya nama, dokumentasikan risiko & batasi update field non-destruktif. **Set status produk eksplisit** (sepakati: `download`/`in_review` agar masuk alur approve, atau `master`).
- **Bundle:** komponen & bundle harus eksis (sudah divalidasi `:56-61`) → kalau tidak, catat ke `product_import_errors` (bukan throw senyap).
- Setiap kegagalan baris → tambah `failed_rows` + insert `product_import_errors`; sukses → `success_rows`. Update `processed_rows`/`progress_percent` per chunk.

---

## 7. Skala Besar

- Sheet importer implement: `ToCollection`/`ToModel`, `WithHeadingRow`, `WithChunkReading` (mis. 500/iterasi), `WithBatchInserts` (mis. 200), `ShouldQueue`, `WithEvents` (AfterImport → finalize state).
- Job berjalan di queue **`product`** (`config('queue.names.product')`).
- Hindari `DB::transaction` per-baris yang berat; gunakan transaksi per-chunk atau per-baris ringan agar 1 baris gagal tak membatalkan chunk.
- File disimpan di `storage` (bukan diproses dari request lifetime).
- `progress_percent` di-update via `AfterChunk`/event.

---

## 8. Komponen & File

**Baru**
- Migration `product_import_batches`, `product_import_errors`.
- Model `ProductImportBatch`, `ProductImportError` (+ relasi).
- Export `Modules/Product/app/Exports/ProductTemplateExport.php` & `BundleTemplateExport.php` (FromArray + WithHeadingRow + WithStyles + WithMultipleSheets utk sheet Petunjuk).
- Export `ImportErrorReportExport.php` (laporan error).
- Job `Modules/Product/app/Jobs/ProcessProductImportJob.php` (dispatch Excel::import berbasis batch, finalize state).
- Service: `ImportBatchService` (buat batch, update progress/counts, finalize) + perluas `ProductImportService` (kembalikan hasil per-baris, bukan void).
- Request: `ImportSingleRequest`, `ImportBundleRequest` (validasi file).
- Rules row: kelas `WithValidation` di sheet importer + `SkipsOnFailure`/`SkipsOnError`.

**Diubah**
- `ProductImportController` — upload→batch→202; tambah `batches`, `show`, `errors`, `errorsDownload`; template pakai Export generated.
- `ProductDataSheetImport` / `BundleDataSheetImport` — tambah chunk/validation/skips + tulis ke batch & errors (injeksi batch id).
- `ProductImportRepository` — `upsertProductByName` ganti/lengkapi kunci match + set status; helper counts.
- `routes/api.php` — tambah route batches/errors.

---

## 9. Rencana Test

**Unit/Service**
- `ProductImportService::processSingleProductRow` — produk+varian dibuat; multi-baris satu grup → 1 produk N varian; kategori/brand auto-create; media maks sesuai.
- `processBundleRow` — komposisi terbentuk; komponen/bundle tak ada → error tercatat (bukan throw senyap).
- Resolve kategori (id ada / nama / kosong→Uncategorized) & brand.

**Feature**
- Upload single valid → 202 + batch; setelah job → state `done`, `success_rows` benar, produk ada di DB.
- Upload dengan baris campur valid/invalid → state `done_with_errors`; `failed_rows` & `product_import_errors` terisi dengan pesan & kolom; produk valid tetap masuk.
- Upload bundle valid & invalid (komponen tak ada) → error tercatat.
- File salah format/mime → 422; file korup → batch `failed` + `error_message`.
- Template single & bundle → 200, content-type xlsx, header sesuai kontrak parser.
- Endpoint `batches`, `show`, `errors`, `errors/download` → bentuk & paginasi benar.
- Idempotensi: re-upload file sama tidak menggandakan varian (match by SKU).

**Skala (smoke)**
- File ~5–10k baris → job selesai tanpa OOM (pakai `Excel::fake()` / dataset sintetis); progress termonitor.

Target: semua hijau + tidak ada regresi `ProductPantauanTest`/`ProductLifecycleTest`.

---

## 10. Fase Implementasi

**Fase 1 — Fondasi batch & feedback (inti)**
1. Migration + model `product_import_batches` & `product_import_errors`.
2. `ImportBatchService` (create/update/finalize).
3. Controller upload → simpan file + buat batch + dispatch job (202).
4. Job + sheet importer dengan `SkipsOnError/Failure` → tulis errors & counts.
5. Endpoint `show`/`errors`. → **Hasil:** feedback hasil/error nyata, tak lagi senyap.

**Fase 2 — Validasi & skala**
6. `WithValidation` per baris (single & bundle) + `SkipsEmptyRows`.
7. `WithChunkReading` + `WithBatchInserts` + queue `product`.
8. Perbaiki match produk + set status eksplisit.

**Fase 3 — Template generated**
9. Export template single & bundle (data + sheet Petunjuk) → ganti file statis.
10. Export laporan error + endpoint download.

**Fase 4 — Test & hardening**
11. Unit + feature + smoke skala; perbaikan dari temuan.

---

## 11. Definition of Done
- [ ] Upload single & bundle async → batch tercatat, job jalan di queue `product`.
- [ ] Hasil per-baris: `success/failed` + daftar error (baris, kolom, pesan) via API & unduhan.
- [ ] Validasi per-baris aktif; baris invalid dilewati & tercatat, baris valid tetap masuk.
- [ ] Chunk + batch insert; file besar (≥5k baris) tidak OOM/timeout.
- [ ] Template single & bundle ter-generate (header sinkron dengan parser) — tidak lagi 404.
- [ ] Match produk konsisten + status produk hasil import eksplisit & disepakati.
- [ ] Test unit + feature hijau; tanpa regresi modul Product.

---

## 12. Risiko & Catatan
- **Perubahan kontrak**: upload jadi 202 async — perlu disepakati dengan FE (polling status). Bila FE belum siap, sediakan mode sinkron kecil (≤N baris) sebagai fallback opsional.
- **Match by name** legacy: migrasi ke kunci SKU/group code perlu hati-hati agar tidak menimpa produk lama; default: update non-destruktif.
- **Status produk**: keputusan `download` vs `master` memengaruhi apakah produk langsung muncul di katalog master / bisa di-upload. Default aman: `download` (masuk alur approve).
- **Multi-varian tanpa variation options**: import tidak mengisi atribut variasi → akan kena flag "gagal upload" di Pantauan. Pertimbangkan kolom variasi di template (fase lanjutan).
- **Memori template generated**: untuk file besar gunakan streaming export.
