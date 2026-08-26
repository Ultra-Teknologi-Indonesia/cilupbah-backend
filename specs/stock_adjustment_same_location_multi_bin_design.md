# Feature: Penyesuaian Multi-SKU pada Rak Berbeda dalam Satu Lokasi

## Requirements

While penyesuaian stok dibuat untuk satu lokasi gudang, when beberapa SKU atau
baris SKU yang sama dipilih pada rak berbeda di lokasi tersebut, the system
shall allow all rows to be submitted dalam satu dokumen.

While rak berasal dari lokasi gudang berbeda, when the adjustment is submitted,
the system shall reject the request and keep the existing location validation.

## Architecture

### Frontend

- Penyesuaian mempertahankan satu `location_id` dokumen.
- Baris form diberi identitas unik sehingga SKU yang sama dapat ditambahkan
  lebih dari sekali untuk rak berbeda.
- Submit tetap memvalidasi setiap baris memiliki rak dan delta non-zero.

### Backend

- `StockAdjustmentService` memvalidasi semua `bin_id` berada pada
  `location_id` dokumen.
- Satu dokumen tetap dapat menyimpan beberapa item dengan `item_id` sama
  selama `bin_id` berbeda.
- Proses movement dan inventory dijalankan per baris dengan pasangan item/rak.

### Security

- Endpoint dan authorization yang sudah ada dipertahankan.
- Validasi server tetap menjadi sumber kebenaran; FE tidak dapat memasukkan
  rak dari lokasi lain.
- Nilai SKU, rak, dan qty tetap divalidasi oleh request/service sebelum mutasi
  stok.

## Implementation Plan

- [x] Audit validasi backend dan form FE.
- [x] Izinkan baris SKU yang sama pada rak berbeda di FE.
- [x] Tambahkan regression test untuk SKU sama, rak sama, dan lokasi berbeda.
- [x] Jalankan test backend dan typecheck FE; lint FE masih memiliki error
  existing pada komponen ini di luar perubahan fitur.
