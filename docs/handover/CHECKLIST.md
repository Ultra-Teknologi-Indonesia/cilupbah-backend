# Checklist Serah-Terima

## A. Artefak kode

- [ ] Repository backend, frontend, dan mobile diserahkan.
- [ ] Branch, commit, tag, dan image production dicatat.
- [ ] CI/CD workflow dan aturan deployment dicatat.
- [ ] Migration terakhir dan statusnya dicatat.

## B. Dokumentasi

- [ ] Manual Web final.
- [ ] Manual Mobile final.
- [ ] Swagger/OpenAPI generated dari versi yang diserahkan.
- [ ] Postman collection tanpa token aktif.
- [ ] Runbook deployment, queue, database, Redis, backup, rollback.
- [ ] Daftar permission/role dan akun admin awal.
- [ ] Matriks URL webhook, OAuth callback, signature, dan pemilik konfigurasi.
- [ ] Daftar versi image dan digest yang benar-benar diterima.

## C. Akses dan aset

- [ ] GitHub/repository access.
- [ ] VPS/k3s/Kubernetes access.
- [ ] Domain/DNS/SSL/Cloudflare.
- [ ] PostgreSQL backup dan restore procedure.
- [ ] Redis/PgBouncer/R2 atau object storage.
- [ ] Sentry/monitoring.
- [ ] Marketplace app, store, callback, webhook dan token ownership.
- [ ] Google Play Console dan mobile signing asset.
- [ ] Domain DNS, Cloudflare, sertifikat origin, dan akses registrar.

## D. Validasi akhir

- [ ] Login dan permission diuji.
- [ ] Inbound → putaway → stok diuji.
- [ ] Order → picking → packing → shipping diuji.
- [ ] Retur/cancel diuji.
- [ ] Webhook dan retry diuji dengan data sintetis/staging.
- [ ] Export/download dan object storage diuji.
- [ ] Queue/Horizon/monitoring diuji.
- [ ] Known limitations dan outstanding issue disetujui tertulis.
- [ ] Error koneksi marketplace (termasuk Shopee `Partner and shop has no linked`)
  memiliki pemilik tindakan dan bukti uji setelah perbaikan.
- [ ] `failed_jobs` dan data operasional lama memiliki keputusan tertulis
  (dipertahankan, retry, atau dibersihkan); tidak dibersihkan hanya untuk
  membuat metrik terlihat kosong.

## E. Administrasi

- [ ] BAST berisi daftar deliverable dan status penerimaan.
- [ ] Status setiap termin/pembayaran disepakati.
- [ ] Ownership source code mengikuti pembayaran sesuai kontrak.
- [ ] Tanggal Go-Live dan awal masa garansi dicatat.
- [ ] Kanal support dan batas pemeliharaan pasca-garansi dicatat.

## F. Kriteria BAST final

- [ ] Client menerima source repository dan commit/tag release.
- [ ] Client dapat login ke web dan mobile sesuai role.
- [ ] Satu alur uji order, stok, picking, AWB/label, retur, dan laporan berhasil.
- [ ] URL production dan callback seluruh channel telah diuji.
- [ ] Backup dapat direstore pada environment uji.
- [ ] Tidak ada secret yang dikirim melalui repository atau chat.
- [ ] Known limitation, pihak yang bertanggung jawab, dan tanggal tindak lanjut
  ditandatangani kedua pihak.
