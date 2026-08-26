# Feature: Guard Rak Inbound pada Penyesuaian Stok

## Requirements

While pengguna membuat atau mengimpor penyesuaian stok, when memilih rak,
system shall hanya menerima rak final pada gudang yang dipilih.

While stok masih berada di bin inbound/DEFAULT, when pengguna mencoba
menjadikannya sumber penyesuaian, system shall menolak aksi dan mengarahkan
pengguna untuk melakukan putaway terlebih dahulu.

## Architecture

### Frontend

- Dropdown rak penyesuaian meminta hanya `is_inbound=false`.
- Data rak dari hasil SKU juga disaring kembali sebelum dirender sebagai
  defense in depth.
- Rak historis inbound tidak dipertahankan sebagai pilihan saat dokumen diedit.

### Backend

- Service penyesuaian tetap menjadi source of truth dan hanya menerima bin
  final yang ada pada lokasi dokumen.
- Preview import mendeteksi bin inbound lebih awal dan mengembalikan error per
  baris; fallback rak otomatis hanya dapat memilih bin final.

### Security and integrity

- Endpoint tetap memakai autentikasi dan otorisasi yang ada.
- Validasi berada di service, sehingga UI, API, dan confirm import tidak dapat
  melewati kebijakan.
- Tidak ada perubahan saldo atau log channel bila validasi gagal.

## Verification

- Import dengan kode rak DEFAULT ditolak pada preview.
- Create penyesuaian langsung dengan bin inbound ditolak sebelum transaksi.
- Dropdown FE meminta dan menyaring hanya rak final.
