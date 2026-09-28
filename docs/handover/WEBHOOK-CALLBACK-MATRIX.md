# Matriks Webhook dan Callback Marketplace

Dokumen ini menjadi daftar serah-terima integrasi channel. URL aktual dan secret
tidak ditulis di repository; pemilik integrasi harus mengisi nilai pada password
manager/portal marketplace dan mencatat tanggal verifikasi.

## Endpoint production yang disepakati

| Item | Nilai | Status audit |
|---|---|---|
| Backend public base URL | `https://be-superapp.ultra-fit.id` | Terjangkau; `/healthz` dan Swagger mengembalikan HTTP 200 pada audit publik 28 Sep 2026 |
| Live Redirect URL Domain | `https://be-superapp.ultra-fit.id/` | Dikonfirmasi client sudah diubah di portal channel |
| Webhook inbox | Endpoint channel pada route backend | Route harus diuji dengan signature/payload resmi; jangan menyimpulkan dari HTTP GET |
| TLS publik | Sertifikat `*.ultra-fit.id` | Valid di edge publik sampai 13 Des 2026; ini belum membuktikan TLS origin Ingress |
| TLS/Ingress origin | Host canonical `be-superapp.ultra-fit.id` | Belum lulus: manifest repository masih berisi `backend.ultra-fit.id`; secret `cilupbah-tl` dan cert-manager CRD belum ditemukan pada audit cluster |

## URL yang telah dikonfirmasi client

| Channel | OAuth callback | Webhook | Catatan |
|---|---|---|---|
| Shopee | `https://be-superapp.ultra-fit.id/api/v1/shopee/callback` | Belum diberikan pada konfirmasi ini | Live Redirect URL Domain: `https://be-superapp.ultra-fit.id/` |
| TikTok Shop | `https://be-superapp.ultra-fit.id/api/v1/tiktok/callback` | `https://be-superapp.ultra-fit.id/api/v1/tiktok/webhook` | POST tanpa signature resmi ditolak; route publik terjangkau |
| Lazada | `https://be-superapp.ultra-fit.id/api/v1/lazada/callback` | Belum diberikan pada konfirmasi ini | Tambahkan domain pada **Authorized Seller Whitelist** di console Lazada sebelum connect |

## Checklist per channel

| Channel | Webhook order/status | OAuth callback | Shipping/label callback atau polling | Pemilik verifikasi |
|---|---|---|---|---|
| Shopee | [ ] URL webhook [ ] signature [ ] test event | [x] callback diberikan client [ ] reconnect test | [ ] AWB/label test | Client/integrator |
| TikTok Shop | [x] webhook diberikan client [ ] signature [ ] test event | [x] callback diberikan client [ ] reconnect test | [ ] AWB/label test | Client/integrator |
| Lazada | [ ] URL webhook [ ] signature [ ] test event | [x] callback diberikan client [ ] reconnect test [ ] Authorized Seller Whitelist | [ ] AWB/label test | Client/integrator |
| WooCommerce/other | [ ] URL production [ ] secret [ ] test event | [ ] bila digunakan | [ ] bila digunakan | Client/integrator |

## Verifikasi tanpa membocorkan secret

1. Pastikan DNS `be-superapp.ultra-fit.id` menunjuk ke Cloudflare/origin yang
   benar. Audit publik menemukan IP Cloudflare; origin belum dapat disimpulkan
   dari DNS saja.
2. Pastikan Ingress memakai host canonical tersebut dan sertifikat valid. Audit
   source saat ini masih menemukan `backend.ultra-fit.id`, sehingga manifest
   harus diperbarui dan rollout diverifikasi sebelum BAST.
3. Untuk Lazada, masukkan domain pada **Authorized Seller Whitelist** sebelum
   melakukan connect.
4. Kirim satu event uji dari portal channel atau staging.
5. Pastikan HTTP 2xx diterima, event tercatat di inbox, dan job diproses satu kali.
6. Uji event duplikat; hasilnya tidak boleh membuat order atau stok ganda.
7. Catat timestamp WIB, event ID, order reference yang disamarkan, dan hasilnya.

## Cutover dan rollback

- Jangan mematikan endpoint lama sebelum event baru terbukti masuk dan diproses.
- Saat cutover, ubah callback di portal marketplace, lakukan satu event uji, lalu
  pantau inbox dan queue selama periode yang disepakati.
- Jika event tidak masuk, kembalikan callback ke endpoint lama hanya setelah
  memastikan tidak terjadi pemrosesan ganda; simpan bukti perubahan dan waktu.

## Batasan

Webhook hanya memberi notifikasi. AWB/label dapat tetap asynchronous dan
tergantung rate limit/API marketplace; callback yang benar tidak menjamin resi
langsung tersedia.
