# Picking Invoice on Scan

## Tujuan

Saat SKU berhasil di-scan pada picking, stok fisik langsung berkurang dan
nomor/data invoice disimpan. Dokumen PDF tidak dibuat pada proses picking;
PDF hanya dirender ketika endpoint invoice dipanggil.

## Keputusan desain

- Invoice dibuat idempotent per order pada scan pertama dengan status `DRAFT`.
- Item invoice berasal dari order sehingga nomor dan data dokumen sudah stabil
  sejak scan pertama.
- Invoice berubah menjadi `OPEN` setelah seluruh item order selesai dipick.
- Mutasi stok pada scan memakai source `INVOICE` dan tetap dicatat dalam
  transaksi yang sama dengan allocation picking.
- Finish pick/job hanya melakukan finalisasi invoice dan tidak mengurangi stok
  lagi untuk allocation yang sudah memiliki komitmen fisik.
- Unpick/cancel mengembalikan stok dari movement hasil scan dan tidak membuat
  invoice/stock posting ganda.
- PDF endpoint bersifat read-only terhadap invoice; data invoice tidak dibuat
  saat PDF diminta.

## Client

- Web dan Mobile melewati dialog qty bila sisa qty item tepat satu, lalu
  mengirim `qty_delta: 1` melalui endpoint existing.
- Qty lebih dari satu tetap memakai input qty.
- Tidak ada perubahan kontrak request wajib untuk client lama.

## Keamanan dan konsistensi

- Endpoint existing tetap memakai auth, permission, validasi SKU/bin, dan
  warehouse scope.
- Order dan invoice dikunci saat dibuat/finalisasi untuk mencegah duplicate
  invoice ketika dua picker memproses order yang sama.
- Observer jurnal keuangan mengabaikan invoice `DRAFT`; jurnal dibuat saat
  invoice difinalisasi menjadi `OPEN`.
- Tes mencakup scan satu qty, scan ulang, finalize idempotent, PDF tanpa
  insert, unpick reversal, dan kompatibilitas order lama.
