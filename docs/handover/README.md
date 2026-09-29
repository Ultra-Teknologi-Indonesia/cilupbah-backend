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
| [Postman Collection](postman/README.md) | Developer/maintainer | Collection otomatis dari OpenAPI dengan environment staging dan production |
| [Runbook Server](RUNBOOK-SERVER.md) | DevOps/maintainer | Deployment, migrasi, verifikasi, queue, backup, rollback dan insiden |
| [Checklist Serah-Terima](CHECKLIST.md) | Kedua pihak | Daftar artefak, akses, pengujian, known limitations dan BAST |
| [Matriks Webhook & Callback](WEBHOOK-CALLBACK-MATRIX.md) | Admin/DevOps/integrator | URL callback tiap marketplace, pemilik konfigurasi, verifikasi dan cutover |
| [Penerimaan Kondisi Saat Ini](AS-IS-ACCEPTANCE.md) | Kedua pihak | Batas serah-terima saat ini; bukan deklarasi Go-Live |
| [Persetujuan Penerimaan As-is](AS-IS-ACCEPTANCE-AGREEMENT.md) | Kedua pihak | Dokumen formal untuk persetujuan tertulis dan tanda tangan |
| [Known Limitations](KNOWN-LIMITATIONS.md) | Kedua pihak | Keterbatasan resource, marketplace, queue, dan hasil audit |
| [Release Manifest](RELEASE-MANIFEST.md) | Maintainer/owner | Identitas commit, image, digest, dan URL per environment |
| [Akses dan Kepemilikan](ACCESS-AND-OWNERSHIP.md) | Kedua pihak | Daftar akses/aset yang diserahkan melalui kanal aman |

## Peta environment

| Environment | Frontend | Backend | Keterangan |
|---|---|---|---|
| Staging | `https://dev-frontend-app.ultra-fit.id/` | `https://dev-backend-app.ultra-fit.id/` | Untuk uji dan validasi sebelum production |
| Production | `https://app.ultra-fit.id/` | `https://be-superapp.ultra-fit.id/` | URL operasional yang disepakati |

Audit cepat terakhir dibuat pada **28 September 2026, 13:09 WIB** ketika
rollout masih berlangsung. Status pod, Ingress, dan queue pada audit tersebut
adalah snapshot sementara dan harus diverifikasi ulang setelah rollout selesai.

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

Paket dokumen ini disiapkan untuk **handover kondisi saat ini (as-is)**. Ini
tidak sama dengan deklarasi Go-Live. Client dapat menerima kondisi as-is apabila
known limitation, status deployment, dan item yang belum diverifikasi dicatat
serta disetujui tertulis.

Jika kemudian dilakukan Go-Live, gunakan checklist dan bukti terpisah untuk
memastikan Ingress production `be-superapp.ultra-fit.id`, TLS, callback seluruh
marketplace, backup-restore, monitoring, on-call, dan image/digest yang diterima.
