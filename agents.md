# Agent Coding Standards (cilupbah-be)

Dokumen ini adalah panduan standar penulisan kode untuk proyek Laravel (`cilupbah-be`). Semua AI Agent dan _developer_ **WAJIB** mematuhi aturan ini ketika membuat atau memodifikasi fitur baru.

> Standar frontend ada di `../cilupbah-fe/AGENTS.md`. FE dan BE mengikuti pola yang sama: pakai primitive/kontrak bersama, jangan meracik sendiri.

## 0. Orientasi Kode (graphify)

- Proyek punya knowledge graph di `graphify-out/`. Untuk pertanyaan tentang struktur/relasi kode, jalankan `graphify query "<pertanyaan>"` lebih dulu, bukan langsung `grep`/baca file mentah. Pakai `graphify path "<A>" "<B>"` untuk relasi dan `graphify explain "<konsep>"` untuk konsep fokus.
- Setelah mengubah kode, jalankan `graphify update .` agar graph tetap sinkron (AST-only, tanpa biaya API).

## 1. Stack & Struktur Modular

- **Laravel 12 · PHP 8.2+ · PostgreSQL.** Autentikasi API pakai **Laravel Sanctum**; antrean/worker pakai **Laravel Horizon**; dokumentasi API via **l5-swagger**.
- **Kode fitur hidup di `Modules/`, BUKAN di `app/` root** (paket `nwidart/laravel-modules`). Modul yang ada: `AI`, `Auth`, `Channel`, `Dashboard`, `Finance`, `Inbound`, `Inventory`, `IssueTracker`, `Notification`, `Outbound`, `Product`, `Purchase`, `Region`, `Report`, `Sales`, `Supplier`, `Tax`, `Warehouse`, `Warranty`, `Webhook`.
- **Layout tiap modul** (`Modules/<Nama>/`):
  - `app/Http/` — Controllers, Requests, Resources, Middleware
  - `app/Services/` — logika bisnis
  - `app/Repositories/` — akses data
  - `app/Models/`, `app/Jobs/`, `app/Events/`, `app/Listeners/`, `app/Observers/`, `app/Console/`, `app/Providers/`, `app/Support/`
  - `app/Imports/` & `app/Exports/` — Excel (maatwebsite/excel)
  - `database/migrations/`, `database/seeders/`, `routes/`, `config/`, `tests/Feature/`
- Taruh fitur baru di modul yang tepat; jangan menambah kode bisnis di `app/` root. Kalau lintas-modul, komunikasikan lewat Service/Event, bukan memanggil Repository modul lain langsung.

## 2. Arsitektur: Service-Repository Pattern

- **Controllers**: SAMA SEKALI TIDAK BOLEH berisi logika bisnis, manipulasi data kompleks, atau kueri _database_ langsung. Controller hanya bertugas menerima HTTP Request, (opsional) melakukan validasi dasar, meneruskannya ke Service, dan mengembalikan respons API.
- **Services**: Seluruh logika bisnis, integrasi API pihak ketiga (contoh: TikTok, Shopee, Lazada), pemrosesan data, dan _try-catch_ bersarang harus diletakkan di Service. Service akan memanggil Repository untuk mengambil atau menyimpan data.
- **Repositories**: Seluruh interaksi dengan _database_ (kumpulan _query_ Eloquent/DB facade) WAJIB berada di Repository.

## 3. Standar Respons API (ApiResponse & Eloquent Resources)

- Semua Controller **WAJIB** mengembalikan respons dalam format JSON standar dengan menggunakan _trait_ `App\Traits\ApiResponse`.
- Untuk mencegah duplikasi skema/format data, gunakan **Eloquent Resources** (misal: `OrderResource`) saat mengembalikan data _model_.
- **Dilarang keras** mendefinisikan skema JSON secara berulang atau mengembalikan objek _model_ murni.
- **Dilarang keras** menggunakan `view()`, `redirect()->route()`, atau _flash session_ (`with()`) di dalam Controller API.
- Gunakan `$this->successResponse(new MyResource($data))` atau `$this->successPaginatedResponse($paginator)` untuk daftar _paginate_. Jika memakai _resource_ pada _pagination_, aplikasikan transformasinya dulu.
- Kontrak ini adalah bentuk yang dikonsumsi frontend sebagai `ApiResponse<T>` (`{ data, meta }`). Jaga konsistensinya.

## 4. Data Retrieval: Spatie Query Builder

- Seluruh endpoint _listing_ atau _index_ (menampilkan daftar data) WAJIB menggunakan paket `spatie/laravel-query-builder` untuk menangani _pagination_, _sorting_, dan _filtering_ secara otomatis.
- Pemanggilan `QueryBuilder::for(Model::class)` harus dilakukan di dalam **Repository**.
- **Prinsip "pakai jika optimal":** kapan pun Spatie Query Builder memberi manfaat (kueri yang dikendalikan parameter URL — `?search=`, `filter[...]`, `sort=`, `per_page`), Anda WAJIB memakainya. Ini mencakup tidak hanya endpoint index utama, tetapi juga _sub-list_ apa pun yang dapat disaring/diurutkan/dipaginasi oleh frontend.
- **Pengecualian (Eloquent biasa diperbolehkan):** untuk pengambilan **satu record berdasarkan id/kunci** (`find`, `findOrFail`, `value`, `first` by primary/foreign key dari _route param_) atau **list internal/turunan yang tidak digerakkan query-string** (mis. opsi _dropdown_ statis, agregasi detail satu sumber daya), Spatie Query Builder TIDAK memberi nilai tambah — gunakan Eloquent biasa di dalam Repository. Membungkus lookup semacam ini dengan `QueryBuilder::for` justru dianggap salah pakai.
- Aturan praktis: jika frontend bisa mengirim `filter`/`sort`/`search`/`per_page` untuk kueri tersebut → **Spatie**. Jika tidak (hanya ambil 1 record atau daftar tetap) → **Eloquent biasa**.

## 5. Implementasi Pencarian (Full-Text Search & Parameter `?search=`)

- Untuk pencarian teks bebas yang sangat akurat, Anda **WAJIB** menggunakan macro `allowedSearch(...)` yang sudah didefinisikan secara global pada `Illuminate\Database\Eloquent\Builder`.
- Macro ini otomatis menangkap parameter URL `?search=` dan menggunakan PostgreSQL Full-Text Search (`tsvector`, `tsquery`, `ts_rank_cd`) dengan kamus `indonesian`.
- Anda TIDAK PERLU lagi melakukan manual request merge untuk filter, dan JANGAN pakai `AllowedFilter` Spatie untuk meniru search bebas — pakai `allowedSearch`.
- **Contoh Implementasi:**

    ```php
    public function getPaginatedData()
    {
        return QueryBuilder::for(MyModel::class)
            ->allowedSearch('judul', 'konten') // Pencarian otomatis jika ada ?search=
            ->allowedFilters(
                'status'
            )
            ->allowedSorts('created_at', 'name')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }
    ```

### 5.1 Index Wajib untuk Setiap `allowedSearch` Baru

Macro ini menghasilkan `to_tsvector(...) @@ websearch_to_tsquery(...) OR <kolom> ILIKE '%...%'`. Karena kedua sisi di-`OR`, PostgreSQL **hanya** bisa menghindari sequential scan kalau **dua-duanya** punya index. Tanpa index, setiap `?search=` membangun tsvector per baris — inilah penyebab utama endpoint listing terasa lambat.

Jadi setiap kali menambah `allowedSearch(...)` pada tabel yang bisa tumbuh besar, tambahkan juga migrasi index:

```php
$vector = SearchExpression::vector(['judul', 'konten']);
ConcurrentIndex::create(
    'idx_mytable_search_fts',
    'my_table',
    ['judul', 'konten'],
    "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_mytable_search_fts ON my_table USING gin ({$vector})"
);
// lalu satu index trigram per kolom untuk sisi ILIKE
```

Aturan mainnya:

- SQL index **wajib** dibangun lewat `App\Support\SearchExpression`, bukan ditulis tangan. PostgreSQL hanya memakai expression index kalau ekspresi di query identik persis dengan yang diindeks — helper ini satu-satunya sumber kebenaran untuk keduanya.
- Urutan argumen `allowedSearch` tidak berpengaruh: `SearchExpression` menormalkan (sort + dedupe) daftar kolom, jadi satu index melayani semua call site dengan himpunan kolom yang sama.
- Migrasi index pakai `App\Support\ConcurrentIndex` + `public $withinTransaction = false`, supaya tabel tetap bisa ditulis saat index dibangun.
- Relevansi (`ts_rank_cd`) hanya diterapkan kalau request tidak mengirim `?sort=`. Kalau frontend mengirim `sort` eksplisit, sort itu yang dipakai — ini juga yang membuat top-N bisa dijawab langsung dari index.
- **Jangan mencampur kolom dari dua tabel dalam satu `allowedSearch`.** `allowedSearch('pesanan.nomor', 'retur.nomor')` lewat nama tabel hasil `join` menghasilkan satu ekspresi tsvector lintas tabel, dan ekspresi semacam itu **tidak bisa diindeks sama sekali**. Pakai awalan **nama relasi** (`order.nomor`) — macro akan memecahnya jadi `orWhereHas`, sehingga tiap sisi jatuh pada satu tabel dan bisa memakai index-nya sendiri.
- Satu tabel sebaiknya punya **satu himpunan kolom pencarian kanonis** yang dipakai semua layar (lihat `SalesOrder::SEARCH_COLUMNS`). Tiap variasi himpunan butuh index GIN sendiri; menyatukannya menghemat biaya tulis sekaligus membuat perilaku pencarian konsisten.
- Tabel master dan referensi yang kecil (contacts, suppliers, salesmen, channels, taxes, roles, regions, brands, attributes) **tidak perlu** diindeks: seq scan di sana murah, index GIN hanya menambah biaya tulis.

### 5.2 Index Foreign Key untuk Eager Loading

PostgreSQL **tidak** membuat index otomatis untuk `REFERENCES`. Setiap relasi yang di-`with(...)` dari daftar terpaginasi menghasilkan `WHERE <fk> IN (...)`; tanpa index pada kolom FK itu, satu halaman produk/pesanan memicu sequential scan penuh pada tabel anak. Saat menambah relasi baru ke sebuah listing, pastikan kolom FK-nya terindeks.

## 6. Pagination Standar (20 Per Page)

- Semua daftar yang menggunakan _pagination_ **WAJIB** memiliki standar _default_ **20 item per halaman** (bukan bawaan 15).
- Parameter ini harus mengambil input dinamis dari URL `per_page` jika diberikan oleh frontend.
- **Implementasi yang diwajibkan:**
    ```php
    ->paginate(request('per_page', 20))
    ->appends(request()->query());
    ```
    Pemanggilan `appends(request()->query())` sangat penting agar parameter Spatie dan `?search=` tidak hilang saat berpindah halaman _pagination_.

## 7. Otorisasi (Spatie Permission)

- Hak akses pakai `spatie/laravel-permission` (role + permission). **Owner mem-bypass** semua pengecekan.
- Enforce izin di lapisan yang tepat (Policy/gate/middleware) dan konsisten dengan gating di frontend — jangan hanya mengandalkan penyembunyian tombol di FE.
- Saat menambah fitur yang butuh izin baru, daftarkan permission-nya dan pastikan disinkronkan (seeder/`syncPermissions`), bukan hardcode role.

## 8. Aset & Dokumen

- **Media/lampiran/foto** → `spatie/laravel-medialibrary` (media collections), bukan simpan path manual.
- **Import/Export Excel** → `maatwebsite/excel` di `app/Imports` & `app/Exports`. Import besar pakai `ToCollection`/chunk + partial-success, jangan gagal-total satu baris menjatuhkan semuanya.
- **PDF / label / QR** → `barryvdh/laravel-dompdf` + `simplesoftwareio/simple-qrcode`.
- **Pekerjaan berat / integrasi channel** → dorong ke **Job** (Horizon), jangan blok request HTTP.

## 9. Testing & Keamanan Database

- Tes ada di `Modules/<Nama>/tests/Feature`. Jalankan dengan `php artisan test` (atau `rtk` prefix untuk output ringkas).
- **JANGAN PERNAH** `migrate:fresh --env=testing` — itu bisa menghapus database dev `cilupbah`. Andalkan `php artisan test` (RefreshDatabase transaksional), bukan reset skema manual.
- Tambahkan/junjung tes Feature untuk perubahan alur bisnis penting (stok, pesanan, penerimaan, transfer).

## 10. Gaya & Alur Kerja

- Ikuti idiom & penamaan modul di sekitar file yang disunting sebelum memperkenalkan pola baru.
- "Push" untuk repo ini berarti **commit + push langsung ke `origin/main`** (repo staging), bukan membuat PR.
