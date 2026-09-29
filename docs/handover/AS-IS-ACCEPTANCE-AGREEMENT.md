<style>
  @page { size: A4; margin: 2.5cm 2.5cm 2.5cm 3cm; }
  body, table, th, td, p, li { font-family: "Times New Roman", Times, serif; }
  body { font-size: 12pt; line-height: 1.5; color: #000; }
  h1, h2, h3 { font-family: "Times New Roman", Times, serif; }
  h1 { text-align: center; font-size: 16pt; text-transform: uppercase; }
  h2 { font-size: 13pt; margin-top: 20pt; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #000; padding: 6pt; vertical-align: top; }
  .center { text-align: center; }
  .signature td { height: 120pt; vertical-align: bottom; }
</style>

<p class="center"><strong>LAMPIRAN PERSETUJUAN SERAH-TERIMA</strong></p>

# PERSETUJUAN PENERIMAAN SISTEM DALAM KONDISI AS-IS

| Field | Nilai |
|---|---|
| Nomor dokumen | Diisi saat penandatanganan |
| Tanggal | Diisi saat penandatanganan (WIB) |
| Nama sistem | Cilupbah Super App |
| Status penerimaan | **As-is / kondisi saat ini** |
| Status Go-Live | **Tidak dinyatakan melalui dokumen ini** |

## 1. Para pihak

Dokumen ini dibuat dan disetujui oleh:

1. **Pihak Client/Owner**: ________________________________________________
2. **Pihak Developer/Penyedia**: __________________________________________

Selanjutnya disebut bersama-sama sebagai **Para Pihak**.

## 2. Tujuan persetujuan

Dengan menandatangani dokumen ini, Para Pihak menyatakan bahwa sistem
Cilupbah Super App, source code, dokumentasi, dan informasi operasional yang
tercantum pada daftar handover diterima untuk keperluan serah-terima dalam
kondisi yang tersedia pada saat penandatanganan (**as-is**).

Persetujuan as-is berarti kondisi dan keterbatasan yang dijelaskan dalam
dokumen ini diketahui dan diterima. Persetujuan ini **bukan** pernyataan bahwa
sistem telah memenuhi sertifikasi performa tertentu, telah melewati Go-Live,
atau bebas dari seluruh risiko operasional.

## 3. Environment yang diserahkan

| Environment | Frontend | Backend |
|---|---|---|
| Staging | https://dev-frontend-app.ultra-fit.id/ | https://dev-backend-app.ultra-fit.id/ |
| Production | https://app.ultra-fit.id/ | https://be-superapp.ultra-fit.id/ |

URL, callback, TLS, image, dan status pod harus mengacu pada bukti deployment
terakhir yang dilampirkan. Jika rollout masih berlangsung pada saat audit,
snapshot audit dianggap sebagai kondisi sementara dan bukan bukti final setelah
rollout.

## 4. Artefak yang diterima

Para Pihak menyatakan telah menerima atau memiliki akses untuk menerima:

- source code backend, frontend, dan mobile sesuai repository yang disepakati;
- manual Web dan Mobile;
- dokumentasi teknis API/Swagger;
- runbook server, deployment, queue, database, Redis, backup, dan rollback;
- matriks webhook dan callback marketplace;
- release manifest (commit, tag, image, dan digest yang tercatat);
- checklist serah-terima;
- known limitations dan laporan audit server;
- daftar akses dan kepemilikan yang diserahkan melalui kanal aman.

Status dan identitas versi yang diterima wajib diisi pada `RELEASE-MANIFEST.md`
dan ditandatangani atau disetujui oleh kedua pihak.

## 5. Keterbatasan yang diketahui dan diterima

Dengan ini Para Pihak mengakui keterbatasan berikut sebagai bagian dari
penerimaan as-is:

1. Kapasitas deployment menggunakan resource server dan node yang tersedia
   saat ini; dokumen ini tidak menjanjikan kapasitas traffic, jumlah order,
   atau concurrency tertentu.
2. Proses marketplace (Shopee, TikTok, Lazada, dan channel lain) dapat bersifat
   asynchronous, dipengaruhi rate limit, antrean channel, dan ketersediaan API
   pihak ketiga. Waktu terbitnya nomor resi atau label tidak sepenuhnya dapat
   dikendalikan sistem internal.
3. Hasil audit resource, queue, pod, Redis, dan database adalah snapshot pada
   waktu audit. Status tersebut dapat berubah ketika traffic, rollout, atau
   konfigurasi berubah.
4. Known limitation, error yang masih terbuka, pod yang masih rollout, serta
   item yang belum memiliki bukti final harus mengikuti `KNOWN-LIMITATIONS.md`
   dan `CHECKLIST.md`.
5. Optimasi di luar release yang diserahkan, penambahan VPS/node, perubahan
   business logic, dan integrasi baru bukan bagian dari persetujuan ini kecuali
   disepakati tertulis terpisah.

## 6. Hal yang tidak dinyatakan oleh dokumen ini

Dokumen ini tidak:

- menetapkan tanggal Go-Live atau awal masa garansi;
- menggantikan BAST utama atau perubahan kontrak;
- memindahkan password, token, private key, atau secret melalui repository/chat;
- menyatakan backup dapat direstore sebelum bukti uji restore ditandatangani;
- menghapus kewajiban untuk menyelesaikan item akses, ownership, dan support
  yang belum dicentang pada checklist.

## 7. Tanggung jawab setelah serah-terima

| Area | Pihak bertanggung jawab | Catatan |
|---|---|---|
| Source code dan repository | Diisi | Akses dan branch/tag release dicatat |
| VPS/Kubernetes dan deployment | Diisi | Runbook menjadi rujukan operasional |
| Domain, DNS, TLS, dan callback | Diisi | Perubahan marketplace dicatat |
| Database, backup, dan restore | Diisi | Jadwal dan bukti restore dicatat |
| Redis, queue, dan monitoring | Diisi | Kanal eskalasi dicatat |
| Marketplace app/token | Diisi | Secret diserahterimakan melalui kanal aman |
| Support pasca-handover | Diisi | Jam layanan dan batas cakupan dicatat |

## 8. Pernyataan persetujuan

Para Pihak telah membaca, memahami, dan menyetujui bahwa:

- sistem diterima dalam kondisi **as-is** sebagaimana dijelaskan di atas;
- keterbatasan dan item yang belum diverifikasi tidak dianggap sebagai janji
  performa atau fitur yang sudah selesai;
- item yang belum selesai hanya menjadi kewajiban lanjutan apabila dicatat
  secara tertulis dengan pemilik, target waktu, dan kesepakatan terpisah;
- dokumen ini menjadi lampiran handover dan harus dibaca bersama checklist,
  known limitations, release manifest, dan runbook.

### Catatan atau pengecualian yang disepakati

______________________________________________________________________________

______________________________________________________________________________

______________________________________________________________________________

## 9. Tanda tangan

| Pihak Client/Owner | Pihak Developer/Penyedia |
|---|---|
| Nama: ______________________________ | Nama: ______________________________ |
| Jabatan: ___________________________ | Jabatan: ___________________________ |
| Tanda tangan: | Tanda tangan: |
| Tanggal: ___________________________ | Tanggal: ___________________________ |

<p class="center"><em>Dokumen ini tidak memuat password, token, private key, atau nilai secret.</em></p>

## Cara ekspor dengan font Times New Roman

Markdown sendiri tidak memaksa font pada semua viewer. File ini menyertakan
blok CSS yang akan dipakai oleh renderer yang mendukung HTML/CSS. Untuk ekspor
PDF melalui Pandoc, gunakan:

```bash
pandoc AS-IS-ACCEPTANCE-AGREEMENT.md \
  --from=gfm \
  --pdf-engine=wkhtmltopdf \
  -V geometry:a4paper \
  -V margin-left=3cm \
  -V margin-right=2.5cm \
  -o AS-IS-ACCEPTANCE-AGREEMENT.pdf
```

Sebelum ditandatangani, periksa hasil PDF: nama pihak, tanggal, URL environment,
release manifest, dan catatan pengecualian harus sudah terisi.
