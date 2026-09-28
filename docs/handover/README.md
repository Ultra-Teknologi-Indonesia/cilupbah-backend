# Paket Handover Cilupbah Super App

Dokumen ini adalah indeks serah-terima untuk Cilupbah Super App. Paket ini
memisahkan panduan operator, dokumentasi kontrak API, dan runbook operasional
server agar sistem dapat diteruskan tanpa bergantung pada pengetahuan pribadi
developer.

## Dokumen

| Dokumen | Pengguna | Isi |
|---|---|---|
| [Manual Web & Mobile](MANUAL-WEB-MOBILE.md) | Owner, Admin, gudang, CS | Cara menjalankan alur kerja harian dan penanganan error umum |
| [Manual Web](MANUAL-WEB.md) | Owner, Admin, CS | Akses dan rutinitas operator web |
| [Manual Mobile](MANUAL-MOBILE.md) | Warehouse, picker, packer | Instalasi, scan, dan prosedur ketika offline |
| [Dokumentasi API Teknis](API-TECHNICAL.md) | Developer/maintainer | Swagger/OpenAPI, autentikasi, kontrak respons, webhook, queue dan aturan integrasi |
| [Runbook Server](RUNBOOK-SERVER.md) | DevOps/maintainer | Deployment, migrasi, verifikasi, queue, backup, rollback dan insiden |
| [Checklist Serah-Terima](CHECKLIST.md) | Kedua pihak | Daftar artefak, akses, pengujian, known limitations dan BAST |
| [Matriks Webhook & Callback](WEBHOOK-CALLBACK-MATRIX.md) | Admin/DevOps/integrator | URL callback tiap marketplace, pemilik konfigurasi, verifikasi dan cutover |

## Sumber kebenaran

- Kode backend: `cilupbah-be/` (Laravel, modul bisnis, job, migration, manifest Kubernetes).
- Kode web: `cilupbah-fe/` (Next.js App Router, React Query, service dan hook).
- Kode mobile: `cilupbah-mobile/` (Flutter Android).
- Kontrak API generated: `cilupbah-be/storage/api-docs/api-docs.json`.
- Swagger UI backend: `/api/documentation` pada host backend yang dikonfigurasi.
- Konfigurasi deployment: `cilupbah-be/k8s/production/` dan workflow CI/CD di `cilupbah-be/.github/workflows/` serta `cilupbah-fe/.github/workflows/`.
- Audit handover terakhir: 28 September 2026, dengan bukti publik pada folder audit yang diserahkan terpisah.

Dokumen yang berada di root repository seperti PRD, planning, dan audit adalah
referensi pengembangan. Dokumen tersebut tidak menggantikan checklist
serah-terima dan harus diberi status `implemented`, `known limitation`, atau
`not in current release` saat BAST.

## Status dokumen

Dokumen ini sengaja tidak memuat password, token, private key, atau nilai
secrets. Nilai tersebut harus dipindahkan melalui password manager/kanal aman
dan dicatat sebagai item serah-terima, bukan ditempel di repository.

Sebelum ditandatangani, lengkapi item yang berstatus "belum tercatat" pada
runbook, jalankan checklist, lalu kunci versi kode/image yang benar-benar
diterima. Jangan mengganti status tersebut dengan asumsi atau nilai sementara.

## Status penerimaan

Paket dokumen siap dipakai sebagai bahan handover operasional. BAST final belum
boleh menyatakan seluruh konfigurasi production selesai sebelum item berikut
ditandatangani kedua pihak: domain Ingress canonical `be-superapp.ultra-fit.id`,
sertifikat origin/TLS, URL callback marketplace, backup-restore test, akses
monitoring, kontak on-call, dan versi image/digest yang diterima.
