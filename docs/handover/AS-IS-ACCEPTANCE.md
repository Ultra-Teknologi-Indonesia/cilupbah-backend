# Penerimaan Kondisi Saat Ini (As-Is)

Untuk persetujuan tertulis dan tanda tangan kedua pihak, gunakan
[`AS-IS-ACCEPTANCE-AGREEMENT.md`](AS-IS-ACCEPTANCE-AGREEMENT.md) sebagai
lampiran formal dokumen ini.

## Tujuan

Dokumen ini menjadi lampiran handover ketika client menerima source code dan
lingkungan yang ada saat ini tanpa menunggu deklarasi Go-Live. Penerimaan as-is
tidak menghapus kewajiban untuk menjelaskan batasan dan status item yang belum
diverifikasi.

## Environment

| Environment | Frontend | Backend |
|---|---|---|
| Staging | `https://dev-frontend-app.ultra-fit.id/` | `https://dev-backend-app.ultra-fit.id/` |
| Production | `https://app.ultra-fit.id/` | `https://be-superapp.ultra-fit.id/` |

## Bukti snapshot

- Audit cepat: 28 September 2026, 13:09 WIB.
- Artefak audit: `cilupbah-handover-fast-20260928-060951.tar.gz`.
- Namespace yang diaudit: `cilupbah`.
- Node: `temet01bare127.neometal.id`, k3s `v1.36.4+k3s1`.
- Image utama yang tercatat: `v1.0.849`.
- Rollout masih berlangsung saat audit; snapshot bukan bukti kondisi final.

## Yang diserahkan

- Source backend, frontend, dan mobile sesuai repository yang disepakati.
- Dokumentasi manual web/mobile, API, runbook, callback matrix, dan checklist.
- Informasi image/digest yang tercatat pada `RELEASE-MANIFEST.md`.
- Known limitation dan bukti audit yang tersedia.

## Yang bukan bagian dari penerimaan ini

- Pernyataan bahwa sistem sudah Go-Live atau tersertifikasi untuk traffic tertentu.
- Jaminan marketplace menerbitkan AWB/label dalam waktu tetap; proses channel
  dapat asynchronous dan terkena rate limit.
- Penambahan VPS/node, perubahan business logic, atau optimasi di luar release
  yang diserahkan.
- Akses secret yang ditempel di repository atau chat; akses diberikan melalui
  kanal aman dan dicatat di `ACCESS-AND-OWNERSHIP.md`.

## Persetujuan

| Pihak | Nama | Status | Tanggal |
|---|---|---|---|
| Client | Diisi saat penandatanganan | menerima / belum menerima | WIB |
| Developer | Diisi saat penandatanganan | menyerahkan / belum menyerahkan | WIB |
