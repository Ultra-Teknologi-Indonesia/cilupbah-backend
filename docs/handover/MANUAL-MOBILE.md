# Manual Mobile Production

Mobile dipakai untuk pekerjaan fisik gudang. Alur lengkap dan definisi role ada
di [Manual Web & Mobile](MANUAL-WEB-MOBILE.md).

## Persiapan

1. Instal APK/build dari distribusi resmi yang diserahkan.
2. Izinkan kamera dan koneksi jaringan.
3. Login dengan akun warehouse pribadi.
4. Pastikan gudang/tugas yang dipilih benar sebelum scan.

## Alur scan

| Pekerjaan | Urutan |
|---|---|
| Picking | Buka picklist → scan SKU → verifikasi qty → lanjut/selesaikan |
| Putaway | Buka tugas → scan SKU → scan lokasi → konfirmasi |
| Receiving | Pilih penerimaan → scan/isi qty → simpan hasil QC |
| Opname | Pilih gudang/lokasi → scan → simpan hasil sesuai izin |
| Transfer | Scan item → verifikasi asal/tujuan → konfirmasi |

Jika jaringan terputus, jangan menganggap scan tersimpan. Tunggu indikator
berhasil atau verifikasi kembali dari daftar tugas sebelum mengulang.

## Handover build mobile

Client harus menerima versi build, commit/tag, file release, signing asset melalui
kanal aman, minimum OS/device yang didukung, serta akun Play Console/App Store
atau distribusi internal yang menjadi pemilik aplikasi.
