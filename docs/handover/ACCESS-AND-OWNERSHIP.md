# Akses dan Kepemilikan

Dokumen ini mencatat jenis akses yang harus diserahkan. Nilai credential tidak
boleh ditulis di repository, issue, atau chat.

| Aset | Pemilik operasional | Cara serah-terima | Status |
|---|---|---|---|
| Repository backend/frontend/mobile | Client/maintainer | GitHub organization atau transfer repository | Perlu dicatat saat BAST |
| VPS dan k3s | Pemilik infrastruktur | Akun/SSH melalui password manager | Jangan kirim private key di chat |
| DNS, Cloudflare, dan TLS | Pemilik domain | Akses registrar/Cloudflare melalui kanal aman | Pisahkan staging dan production |
| PostgreSQL dan backup | Pemilik infrastruktur | Credential dan prosedur restore melalui kanal aman | Restore test perlu bukti |
| Redis/PgBouncer | Pemilik infrastruktur | Akses terbatas melalui password manager | Jangan menyalin secret ke dokumen |
| R2/object storage | Pemilik akun storage | Nama bucket, policy, dan key melalui kanal aman | Verifikasi upload/download |
| Sentry/monitoring/on-call | Client/ops | Invite akun dan kanal eskalasi | Kontak belum tercatat pada audit |
| Marketplace apps dan toko | Client/integrator | Ownership portal, callback, whitelist, dan token | Lazada whitelist wajib dicatat |
| Google Play dan signing asset | Client/owner aplikasi | Transfer/invite resmi | Jangan menyimpan ke repository |

Serah-terima dianggap lengkap setelah setiap baris memiliki pemilik, penerima,
tanggal, dan bukti akses tanpa membuka nilai secret di dokumen publik.
