# Agent Coding Standards (cilupbah-be)

Dokumen ini adalah panduan standar penulisan kode untuk proyek Laravel (`cilupbah-be`). Semua AI Agent dan _developer_ **WAJIB** mematuhi aturan ini ketika membuat atau memodifikasi fitur baru.

## 1. Arsitektur: Service-Repository Pattern

- **Controllers**: SAMA SEKALI TIDAK BOLEH berisi logika bisnis, manipulasi data kompleks, atau kueri _database_ langsung. Controller hanya bertugas menerima HTTP Request, (opsional) melakukan validasi dasar, meneruskannya ke Service, dan mengembalikan respons API.
- **Services**: Seluruh logika bisnis, integrasi API pihak ketiga (contoh: TikTok), pemrosesan data, dan _try-catch_ bersarang harus diletakkan di Service. Service akan memanggil Repository untuk mengambil atau menyimpan data.
- **Repositories**: Seluruh interaksi dengan _database_ (kumpulan _query_ Eloquent/DB facade) WAJIB berada di Repository.

## 2. Standar Respons API (ApiResponse & Eloquent Resources)

- Semua Controller **WAJIB** mengembalikan respons dalam format JSON standar dengan menggunakan _trait_ `App\Traits\ApiResponse`.
- Untuk mencegah duplikasi skema/format data, gunakan **Eloquent Resources** (misal: `OrderResource`) saat mengembalikan data _model_.
- **Dilarang keras** mendefinisikan skema JSON secara berulang atau mengembalikan objek _model_ murni.
- **Dilarang keras** menggunakan `view()`, `redirect()->route()`, atau _flash session_ (`with()`) di dalam Controller API.
- Gunakan `$this->successResponse(new MyResource($data))` atau `$this->successPaginatedResponse($paginator)` untuk daftar _paginate_. Jika memakai _resource_ pada _pagination_, aplikasikan transformasinya dulu.

## 3. Data Retrieval: Spatie Query Builder

- Seluruh endpoint _listing_ atau _index_ (menampilkan daftar data) WAJIB menggunakan paket `spatie/laravel-query-builder` untuk menangani _pagination_, _sorting_, dan _filtering_ secara otomatis.
- Pemanggilan `QueryBuilder::for(Model::class)` harus dilakukan di dalam **Repository**.
- **Prinsip "pakai jika optimal":** kapan pun Spatie Query Builder memberi manfaat (kueri yang dikendalikan parameter URL — `?search=`, `filter[...]`, `sort=`, `per_page`), Anda WAJIB memakainya. Ini mencakup tidak hanya endpoint index utama, tetapi juga _sub-list_ apa pun yang dapat disaring/diurutkan/dipaginasi oleh frontend.
- **Pengecualian (Eloquent biasa diperbolehkan):** untuk pengambilan **satu record berdasarkan id/kunci** (`find`, `findOrFail`, `value`, `first` by primary/foreign key dari _route param_) atau **list internal/turunan yang tidak digerakkan query-string** (mis. opsi _dropdown_ statis, agregasi detail satu sumber daya), Spatie Query Builder TIDAK memberi nilai tambah — gunakan Eloquent biasa di dalam Repository. Membungkus lookup semacam ini dengan `QueryBuilder::for` justru dianggap salah pakai.
- Aturan praktis: jika frontend bisa mengirim `filter`/`sort`/`search`/`per_page` untuk kueri tersebut → **Spatie**. Jika tidak (hanya ambil 1 record atau daftar tetap) → **Eloquent biasa**.

## 4. Implementasi Pencarian (Full-Text Search & Parameter `?search=`)
- Untuk pencarian teks bebas yang sangat akurat, Anda **WAJIB** menggunakan macro `allowedSearch(...)` yang sudah didefinisikan secara global pada `Illuminate\Database\Eloquent\Builder`.
- Macro ini otomatis menangkap parameter URL `?search=` dan menggunakan PostgreSQL Full-Text Search (`tsvector`, `tsquery`, `ts_rank_cd`) dengan kamus `indonesian`.
- Anda TIDAK PERLU lagi melakukan manual request merge untuk filter.
- Cukup panggil `allowedSearch` dan masukkan nama kolom-kolom yang ingin disertakan dalam pencarian FTS.
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
            // ...
    }
    ```

## 5. Pagination Standar (10 Per Page)

- Semua daftar yang menggunakan _pagination_ **WAJIB** memiliki standar _default_ **10 item per halaman** (bukan bawaan 15).
- Parameter ini harus mengambil input dinamis dari URL `per_page` jika diberikan oleh frontend.
- **Implementasi yang diwajibkan:**
    ```php
    ->paginate(request('per_page', 10))
    ->appends(request()->query());
    ```
    Pemanggilan `appends(request()->query())` sangat penting agar parameter Spatie dan `?search=` tidak hilang saat berpindah halaman _pagination_.
