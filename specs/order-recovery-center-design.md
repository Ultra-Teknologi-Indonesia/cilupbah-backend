# Order Recovery Center — Design

## Tujuan

Satu halaman operasional untuk memantau webhook/order yang tertahan serta menarik order, nomor resi, dan label secara langsung. Request HTTP melakukan pekerjaan sinkron dan mengembalikan hasil setiap pesanan tanpa membuat job recovery baru.

## Kontrak perilaku

- Marketplace dapat menjawab resi/label belum siap. Hasil menjadi `waiting_marketplace`, bukan spinner tanpa batas.
- Maksimum 20 pesanan dan deadline orkestrasi 25 detik per request agar PHP worker, koneksi database, CPU, dan RAM tetap terkendali.
- Pesanan yang sudah memiliki resi dan label siap dilewati tanpa API channel ulang.
- Lock per nomor pesanan mencegah klik berulang atau dua aksi berjalan bersamaan.
- Monitoring menampilkan batch label terbaru yang memuat pesanan, status item, dan jumlah seluruh batch yang pernah memuat pesanan tersebut.
- Sinkronisasi batch hanya mengambil anggota batch yang belum memiliki resi/label siap. Lock bersifat per batch, sehingga batch lain tetap dapat diproses.
- Satu request batch memproses maksimum 20 anggota atau 25 detik. Respons mengembalikan jumlah sisa; batch besar dapat dilanjutkan tanpa memonopoli worker HTTP.
- Label yang berhasil dipulihkan langsung dipasang ke item batch dan, saat seluruh anggota sudah terminal, PDF batch difinalisasi dalam request yang sama tanpa membuat job finalisasi baru.
- Aksi tidak memanggil driver kurir otomatis dan tidak mengubah business logic fulfillment/channel.
- Excel/CSV hanya menjadi sumber nomor pesanan; maksimum 2 MiB dan 20 nomor unik.

## API

### GET `/api/v1/operations/order-audit/report`

Server-side listing memakai Spatie QueryBuilder, `allowedSearch`, filter channel/status webhook/status audit/tanggal WIB, sort, dan pagination 20/50/100/200. Respons juga memuat `tracking_number`, `shipping_label_status`, `shipping_label_prepared_at`, dan `recovery_state`.

### POST `/api/v1/operations/order-recovery/sync`

```json
{
  "action": "all",
  "items": [
    {"reference": "ORDER-001", "channel": "shopee", "shop_id": "123"},
    {"reference": "ORDER-002", "channel": "tiktok", "shop_id": "456"}
  ]
}
```

`action` adalah `order`, `awb`, `label`, atau `all`. Response HTTP 200 memuat ringkasan, durasi, dan hasil partial-success setiap item.

### POST `/api/v1/operations/order-recovery/import`

Multipart `file`, `action`, serta opsional `channel`/`shop_id`. Header referensi yang didukung: `nomor_pesanan`, `no_pesanan`, `order_no`, `order_id`, atau `reference`.

### POST `/api/v1/operations/order-recovery/batches/{batch}/sync`

Memulihkan langsung anggota batch label yang belum siap, tanpa membuat job recovery baru. Response memakai kontrak hasil pemulihan yang sama dan menambahkan metadata `batch`, `remaining`, serta `has_more`. Endpoint dilindungi warehouse scope, permission edit pesanan, rate limit, lock per batch, batas 20 anggota, dan deadline 25 detik.

## Struktur

- Controller: validasi dan serialisasi response saja.
- Service: orkestrasi sinkron, deadline, lock, idempotensi, dan error user-facing.
- Repository: lookup database dengan warehouse scope.
- Import class: ekstraksi referensi tanpa business logic.
- Frontend: Service → React Query Hook → Component.

## Keamanan dan ketahanan

- Sanctum dan `owner|edit-pesanan` untuk seluruh aksi; `owner|view-pesanan` untuk monitoring.
- Rate limit endpoint, validasi MIME/ukuran/jumlah, warehouse authorization, dan audit log ringkas.
- Tidak ada queue retry tersembunyi pada jalur recovery; opsi recovery asynchronous pada service lama dimatikan hanya untuk request ini.
- Exception satu item tidak menggagalkan item lain dan detail SQL/internal tidak ditampilkan ke pengguna.

## Verifikasi

- Feature test permission, batas batch, CSV sinkron, ready-skip, serta tidak adanya job retry label tersembunyi.
- Contract test label lama tetap lulus.
- FE typecheck, lint, dan production build.
