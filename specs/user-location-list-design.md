# Feature: tampilkan lokasi pada daftar pengguna

## Requirements

- Saat pengguna membuka daftar pengguna, sistem menampilkan lokasi yang ditugaskan pada setiap pengguna.
- Jika pengguna tidak dibatasi ke lokasi tertentu, sistem menampilkan `Semua gudang`.
- Daftar lokasi yang sudah ada pada API dan pengaturan akses tetap menjadi sumber data yang sama.
- Pengguna yang tidak memiliki hak melihat pengguna tetap tidak dapat mengakses data melalui API.

## Architecture

### Frontend

- Gunakan field `locations` dari respons user yang sudah tersedia.
- Tambahkan kolom `Lokasi` pada tabel daftar pengguna.
- Ringkas daftar panjang agar tabel tetap terbaca; nama lengkap tersedia melalui tooltip browser.
- Tampilkan `Semua gudang` untuk assignment kosong, konsisten dengan halaman detail dan export.

### Backend

- Tidak ada perubahan skema atau business logic.
- `ProfileResource` tetap menyajikan lokasi dari `UserService::attachProfileContext()`.
- Tambahkan regression test bahwa endpoint daftar user mengembalikan lokasi yang ditugaskan.

### Security

- Endpoint tetap menggunakan autentikasi dan permission yang sudah berlaku.
- Lokasi hanya dibaca dari relasi user yang telah diproses server, bukan dari input tampilan.
- Tidak menambahkan data sensitif baru ke respons.

## Acceptance criteria

- Kolom `Lokasi` tampil pada daftar pengguna desktop dan tetap dapat dibaca pada layar kecil melalui scroll tabel.
- Pengguna dengan satu atau beberapa lokasi melihat nama lokasi yang benar.
- Pengguna tanpa assignment melihat `Semua gudang`.
- Test backend dan build FE lulus.
