# Manual Web Production

Dokumen ini adalah indeks cepat untuk operator web. Aturan lengkap dan
penanganan error ada di [Manual Web & Mobile](MANUAL-WEB-MOBILE.md).

## Akses

- URL: `https://app.ultra-fit.id/`
- Login menggunakan akun pribadi sesuai role.
- Jika halaman tidak dapat dibuka, catat waktu WIB dan pesan yang tampil; jangan
  mengubah konfigurasi browser atau endpoint API secara manual.

## Rutinitas operator

1. Pastikan gudang, channel, dan toko yang dipakai aktif.
2. Periksa pesanan baru dan filter tanggal/channel.
3. Proses pesanan sesuai urutan: verifikasi status → resi/label → picklist →
   picking → packing → shipping.
4. Pantau **Monitor Stok** setelah inbound, outbound, transfer, adjustment,
   opname, atau retur.
5. Unduh laporan melalui pusat download; tunggu status pekerjaan tercatat.

## Aturan aman

- Jangan klik retry berulang pada item yang masih `Pending`.
- Jangan membuat order atau stok kedua ketika status masih diproses.
- Simpan nomor order/SKU/toko, waktu, dan pesan error untuk eskalasi.
- Jangan membagikan token marketplace atau password.

## Eskalasi

Gunakan [Runbook Server](RUNBOOK-SERVER.md) untuk insiden queue, webhook,
AWB/label, dan storage. Operator tidak melakukan `queue:retry all`, truncate
database, atau force delete pod.
