# Feature: Pemotongan Stok Fisik Saat Scan Picking

## Scope

Perubahan backend-only. Endpoint scan, payload, dan response tetap dipertahankan
agar web dan mobile tidak membutuhkan perubahan.

## Requirements

- Saat scan picking berhasil, sistem harus mengurangi stok fisik dari rak asal
  dalam transaksi database yang sama dengan pembuatan alokasi picking.
- Scan ulang yang tidak valid harus ditolak dan tidak boleh mengurangi stok dua kali.
- Koreksi/unpick harus mengembalikan stok ke rak asal dengan ledger pembalikan.
- Finish pick tidak boleh mengurangi stok fisik untuk kedua kalinya.
- Bundle harus mengurangi komponen sesuai komposisi bundle.
- Jika pemotongan stok gagal, alokasi dan perubahan qty pick harus rollback.
- FE dan mobile tetap memakai API yang sama.

## Semantics

- `on_order` tetap merepresentasikan reservasi order aktif dan dilepas sesuai
  lifecycle order yang sudah ada.
- `on_hand` pada bin asal berkurang saat scan valid.
- `physical_committed_qty` menjadi penanda bahwa alokasi tersebut sudah memiliki
  mutasi fisik sehingga proses finish pick dapat bersifat idempotent.

## Architecture

### Frontend/mobile

Tidak ada perubahan komponen, payload, endpoint, atau response. Perubahan yang
terlihat hanya angka stok/rak dapat berubah segera setelah scan berhasil.

### Backend

`PicklistService::pickItem` tetap menjadi entry point. Dalam transaksi yang sama:

1. Kunci item picklist dan inventory bin.
2. Validasi qty, rak, assignment, dan stok.
3. Buat alokasi.
4. Konsumsi stok bin melalui `StockService::consumeFromBin`.
5. Tandai alokasi dengan `physical_committed_qty` dan `movement_id`.
6. Update `qty_picked`.

`PicklistInvoiceStockService` hanya memproses alokasi yang belum memiliki
komitmen fisik. Dengan demikian finish pick dan job retry aman dipanggil ulang.

### Security and consistency

- Endpoint existing tetap memakai auth dan permission `edit-picking`.
- Input tetap divalidasi oleh `PickItemRequest`.
- Row locking dan stock lock mencegah dua operator mengurangi stok bin yang sama
  secara tidak konsisten.
- Database transaction memastikan tidak ada qty pick tanpa mutasi stok atau
  mutasi stok tanpa alokasi.
- Bundle memakai jalur `StockService` yang sudah mengunci dan mencatat komponen.

## Failure handling

- Stok tidak cukup: request gagal, tidak ada perubahan permanen.
- Bin tidak valid/inbound: request gagal, tidak ada perubahan permanen.
- Unpick/koreksi: stok dikembalikan melalui `PICKING_REVERSAL` dan commitment
  allocation dikurangi.
- Finish pick/job retry: allocation yang sudah committed dilewati.

## Acceptance tests

- Scan 1 qty mengurangi `on_hand` bin sebesar 1.
- Scan qty yang sama dua kali tidak boleh melewati `qty_ordered`.
- Finish pick setelah scan tidak membuat movement kedua.
- Unpick mengembalikan `on_hand` dan mencatat reversal.
- Bundle mengurangi komponen dengan pengali yang benar.
- Kegagalan stok melakukan rollback terhadap allocation dan qty pick.
- Kontrak API existing tetap lulus tanpa perubahan FE/mobile.
