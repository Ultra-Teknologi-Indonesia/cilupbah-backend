# Agent Coding Standards (cilupbah-be)

Dokumen ini adalah panduan standar penulisan kode untuk proyek Laravel (`cilupbah-be`). Semua AI Agent dan *developer* **WAJIB** mematuhi aturan ini ketika membuat atau memodifikasi fitur baru.

## 1. Arsitektur: Service-Repository Pattern
- **Controllers**: SAMA SEKALI TIDAK BOLEH berisi logika bisnis, manipulasi data kompleks, atau kueri *database* langsung. Controller hanya bertugas menerima HTTP Request, (opsional) melakukan validasi dasar, meneruskannya ke Service, dan mengembalikan respons API.
- **Services**: Seluruh logika bisnis, integrasi API pihak ketiga (contoh: TikTok), pemrosesan data, dan *try-catch* bersarang harus diletakkan di Service. Service akan memanggil Repository untuk mengambil atau menyimpan data.
- **Repositories**: Seluruh interaksi dengan *database* (kumpulan *query* Eloquent/DB facade) WAJIB berada di Repository.

## 2. Standar Respons (Trait `ApiResponse`)
- Semua Controller **WAJIB** mengembalikan respons dalam format JSON standar dengan menggunakan *trait* `App\Traits\ApiResponse`.
- **Dilarang keras** menggunakan `view()`, `redirect()->route()`, atau *flash session* (`with()`) di dalam Controller (karena proyek ini diorientasikan sebagai API-first), kecuali ada instruksi spesifik untuk *flow* khusus (misal: otorisasi OAuth murni).
- Gunakan `$this->successResponse($data, $message)` untuk respons berhasil.
- Gunakan `$this->errorResponse($message, $statusCode)` untuk respons gagal/error.

## 3. Data Retrieval: Spatie Query Builder
- Seluruh endpoint *listing* atau *index* (menampilkan daftar data) WAJIB menggunakan paket `spatie/laravel-query-builder` untuk menangani *pagination*, *sorting*, dan *filtering* secara otomatis.
- Pemanggilan `QueryBuilder::for(Model::class)` harus dilakukan di dalam **Repository**.

## 4. Implementasi Pencarian (`FuzzyFilter`)
- Untuk pencarian teks (seperti filter `search`), Anda **WAJIB** menggunakan filter kustom `App\Filters\FuzzyFilter` yang sudah mengimplementasikan `ILIKE` dan toleransi *typo* (`pg_trgm`).
- **Contoh Penggunaan:**
  ```php
  public function getPaginatedData()
  {
      return QueryBuilder::for(MyModel::class)
          // PENTING: Gunakan variadic argument untuk allowedFilters (tanpa array bungkus [])
          ->allowedFilters(
              AllowedFilter::custom('search', new FuzzyFilter(), 'column_name'),
              'status'
          )
          ->allowedSorts('created_at', 'name')
          ...
  }
  ```

## 5. Pagination Standar (10 Per Page)
- Semua daftar yang menggunakan *pagination* **WAJIB** memiliki standar *default* **10 item per halaman** (bukan bawaan 15).
- Parameter ini harus mengambil input dinamis dari URL `per_page` jika diberikan oleh frontend.
- **Implementasi yang diwajibkan:**
  ```php
  ->paginate(request('per_page', 10))
  ->appends(request()->query());
  ```
  Pemanggilan `appends(request()->query())` sangat penting agar parameter Spatie (seperti `?filter[...]=...`) tidak hilang saat berpindah halaman *pagination*.
