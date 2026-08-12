# Dry Run Endpoint Channel — Verifikasi Data & Mapping

Checklist verifikasi manual untuk semua channel yang terintegrasi (**Shopee, Lazada, TikTok, WooCommerce**), dijalankan lewat `php artisan tinker` di pod produksi.

Tujuannya satu: memastikan **data berhasil diambil dari marketplace** dan **mapping ke model internal sudah benar**, tanpa mengubah apa pun.

> **Aturan mutlak untuk dokumen ini.** Hanya perintah di Bagian 1–7 yang boleh dijalankan di produksi. Bagian 8 adalah daftar endpoint yang **menulis** — dicantumkan supaya Anda tahu mana yang harus dihindari, bukan untuk dijalankan.

---

## 0. Persiapan

### 0.1 Temukan pod

Namespace dan nama deployment produksi sudah tetap (lihat `k8s/production/`): namespace **`cilupbah`**, deployment aplikasi **`cilupbah-app`** — di samping `cilupbah-horizon` dan `cilupbah-scheduler`.

```bash
kubectl get pods -n cilupbah -l app=cilupbah-app
kubectl get deploy -n cilupbah
```

Semua perintah di dokumen ini menulis namespace dan deployment secara **literal** (`-n cilupbah deploy/cilupbah-app`), jadi bisa ditempel apa adanya tanpa menyiapkan variabel shell lebih dulu — termasuk kalau Anda menempelnya ke sesi baru, ke shell lain, atau ke runbook.

### 0.2 Masuk ke tinker

**Mode interaktif** — dipakai untuk sebagian besar checklist di bawah. Tempel blok PHP-nya satu per satu; variabel dari Bagian 1.3 tetap hidup sepanjang sesi.

```bash
kubectl exec -it -n cilupbah deploy/cilupbah-app -- php artisan tinker
```

**Mode heredoc** — cara paling andal kalau ingin sekali jalan atau menyimpan output. Tanda kutip di `<<'PHP'` **wajib**, supaya shell tidak menginterpolasi `$variabel` PHP Anda.

```bash
kubectl exec -i -n cilupbah deploy/cilupbah-app -- php artisan tinker <<'PHP'
$shopee = \Modules\Channel\Models\ChannelShop::whereHas('channel', fn ($q) => $q->where('code','shopee'))->where('is_active', true)->first();
echo $shopee->shop_id.' / '.$shopee->shop_name."\n";
PHP
```

> **Tiap heredoc adalah proses baru.** Variabel `$shopee`/`$lazada`/`$tiktok`/`$woo` dari Bagian 1.3 **tidak** terbawa antar-blok. Kalau memakai heredoc, deklarasikan ulang variabel yang dibutuhkan di awal setiap blok — atau tetap di mode interaktif.

Mode sekali jalan untuk perintah pendek:

```bash
kubectl exec -i -n cilupbah deploy/cilupbah-app -- php artisan tinker --execute="echo \Modules\Channel\Models\Channel::pluck('code')->implode(',');"
```

> Kalau pod punya beberapa container, tambahkan `-c <container>`.

### 0.3 GOTCHA PsySH — `try/catch` harus SATU BARIS

Tinker memakai PsySH. `try { ... } catch { ... }` yang dipecah ke beberapa baris lewat stdin gagal dengan `PHP Parse error: Cannot use try without catch or finally`.

Solusinya: bungkus dalam satu baris, biasanya sebagai helper. Pakai helper ini untuk membungkus panggilan mana pun di checklist ini supaya satu langkah yang gagal tidak menghentikan sesi — dan supaya pesan errornya terbaca ringkas.

```php
$run = function ($fn) { try { return ['ok' => $fn()]; } catch (\Throwable $e) { return ['err' => $e->getMessage()]; } };

$r = $run(fn () => app(\Modules\Channel\Services\ShopeeProductService::class)->listProductIds($shopee->shop_id));
echo isset($r['ok']) ? count($r['ok']).' produk' : 'ERR '.$r['err'];
```

Body closure boleh multi-baris; yang bermasalah hanya `try/catch` telanjang yang dipecah.

Bagian 1.3 juga menyiapkan `$sum` — pendamping `$run` yang meringkas hasil jadi satu baris (`"12 entri"` atau `"ERR ..."`), dipakai di blok-blok yang memanggil beberapa endpoint sekaligus.

### 0.4 Cetak ringkas, jangan cetak PII

Semua blok di bawah sengaja mencetak ringkasan, bukan objek utuh. Pertahankan itu: output panjang boros dan — untuk data pesanan — membocorkan nama, telepon, dan alamat pembeli ke terminal serta ke histori shell.

Untuk hasil mapping order, `except(['items'])` saja **tidak cukup** — `shipping_full_name`, `shipping_phone`, `shipping_address`, dan `dropshipper_phone` masih ikut tercetak. Pakai daftar ini (di-set sekali di Bagian 1.3):

```php
$PII = ['items','customer_name','customer_phone','customer_email','shipping_full_name','shipping_phone','shipping_address','dropshipper_phone'];
```

Jangan pernah mencetak `access_token`, `refresh_token`, atau `consumer_secret`. Kalau perlu memastikan keberadaannya, cetak panjangnya saja: `echo strlen($shop->access_token);`.

### 0.5 Empat hal yang wajib dipahami sebelum mulai

**Satu.** Hampir semua service channel menerima **`shop_id` eksternal** (kolom `channel_shops.shop_id`), **bukan** UUID `channel_shops.id`. Salah satu yang paling sering bikin bingung — `requireShop()` melakukan `findByShopId()`, jadi memberi UUID akan selalu melempar "Toko tidak ditemukan".

**Dua.** Panggilan read-only tetap bisa menulis satu hal: **refresh token**. Kalau access token sudah kedaluwarsa, service akan otomatis refresh dan menyimpan token baru ke `channel_shops`. Ini efek samping yang wajar dan justru diinginkan — tapi sadari bahwa DB tersentuh.

**Tiga.** Kalau sinkronisasi global sedang di-pause, `pullOrders()` diam-diam mengembalikan `0` tanpa memanggil API sama sekali. Cek dulu supaya Anda tidak salah menyimpulkan "API mati":

```php
app(\Modules\Channel\Services\ChannelSyncSettingService::class)->isPaused();
```

**Empat.** `deploy/cilupbah-app` menjalankan **image yang sedang ter-deploy**, bukan kode di branch Anda. Kalau perbaikan mapper baru di-merge tapi belum di-deploy, checklist ini akan memvalidasi kode lama dan memberi rasa aman palsu. Cek dulu revisi yang jalan:

```bash
kubectl get deploy cilupbah-app -n cilupbah -o jsonpath='{.spec.template.spec.containers[0].image}{"\n"}'
kubectl rollout status deploy/cilupbah-app -n cilupbah
```

Untuk membuktikan fix yang belum ter-deploy, replikasikan logikanya manual di skrip tinker — jangan panggil method aslinya.

---

## 1. Pre-flight — kesehatan semua toko

Jalankan ini lebih dulu. Kalau ada toko yang tokennya mati, semua uji di bawahnya akan gagal dan Anda akan mengejar penyebab yang salah.

### ☐ 1.1 Daftar channel terdaftar

```php
\Modules\Channel\Models\Channel::select('id','code','name','is_active')->get()->toArray();
```

**Lolos bila:** ada `shopee`, `lazada`, `tiktok`, `woocommerce` sesuai yang dipakai, dan `is_active` benar.

### ☐ 1.2 Status koneksi semua toko

```php
\Modules\Channel\Models\ChannelShop::with('channel:id,code')
    ->get()
    ->map(fn ($s) => [
        'channel'       => $s->channel?->code,
        'shop_id'       => $s->shop_id,
        'shop_name'     => $s->shop_name,
        'aktif'         => (bool) $s->is_active,
        'order_sync'    => $s->order_sync_status,
        'token_expired' => $s->token_expires_at?->toDateTimeString(),
        'sisa_menit'    => $s->token_expires_at ? now()->diffInMinutes($s->token_expires_at, false) : null,
        'shadow'        => (bool) $s->is_shadow_mode,
        'disconnected'  => $s->disconnected_at?->toDateTimeString(),
        'error'         => \Illuminate\Support\Str::limit((string) $s->last_error, 120),
    ])
    ->toArray();
```

> `last_error` sengaja dipotong 120 karakter — pesan error mentah dari channel kadang membawa potongan payload berisi token atau data pembeli.

**Lolos bila:** `sisa_menit` positif untuk semua toko aktif, `error` kosong, dan tidak ada `disconnected_at`.

Kalau `sisa_menit` negatif atau mendekati nol, refresh dulu (ini **menulis token**, tapi aman dan memang perlu):

```php
$shop = \Modules\Channel\Models\ChannelShop::where('shop_id', '<SHOP_ID>')->first();
app(\Modules\Channel\Services\ShopeeAuthService::class)->refreshStoreToken($shop->id);
```

Ganti `ShopeeAuthService` sesuai channel: `LazadaAuthService`, `TikTokAuthService`. WooCommerce memakai consumer key/secret dan tidak punya refresh token.

### ☐ 1.3 Siapkan variabel kerja

Semua blok berikutnya memakai variabel ini. Set sekali di awal sesi tinker interaktif — atau ulangi di awal tiap heredoc (lihat 0.2).

```php
$shopee = \Modules\Channel\Models\ChannelShop::whereHas('channel', fn ($q) => $q->where('code','shopee'))->where('is_active', true)->first();
$lazada = \Modules\Channel\Models\ChannelShop::whereHas('channel', fn ($q) => $q->where('code','lazada'))->where('is_active', true)->first();
$tiktok = \Modules\Channel\Models\ChannelShop::whereHas('channel', fn ($q) => $q->where('code','tiktok'))->where('is_active', true)->first();
$woo    = \Modules\Channel\Models\ChannelShop::whereHas('channel', fn ($q) => $q->where('code','woocommerce'))->where('is_active', true)->first();

$run = function ($fn) { try { return ['ok' => $fn()]; } catch (\Throwable $e) { return ['err' => $e->getMessage()]; } };
$sum = fn ($r) => isset($r['err']) ? 'ERR '.\Illuminate\Support\Str::limit($r['err'], 120) : (is_array($r['ok']) ? count($r['ok']).' entri' : $r['ok']);
$PII = ['items','customer_name','customer_phone','customer_email','shipping_full_name','shipping_phone','shipping_address','dropshipper_phone'];

[$shopee?->shop_id, $lazada?->shop_id, $tiktok?->shop_id, $woo?->shop_id];
```

---

## 2. Shopee

Adapter: `Modules\Channel\Adapters\ShopeeAdapter` · Service: `ShopeeProductService`, `ShopeeOrderService`

### ☐ 2.1 Ambil daftar produk — `GET /api/v2/product/get_item_list`

```php
$ids = app(\Modules\Channel\Services\ShopeeProductService::class)->listProductIds($shopee->shop_id);
[count($ids), array_slice($ids, 0, 5)];
```

**Lolos bila:** jumlah masuk akal dibanding Seller Center dan isinya numeric string.

### ☐ 2.2 Status produk — `GET /api/v2/product/get_item_list` (per item_status)

```php
$st = app(\Modules\Channel\Services\ShopeeProductService::class)->fetchProductStatuses($shopee->shop_id);
[count($st), array_slice($st, 0, 3, true)];
```

**Lolos bila:** tiap entri punya `status` bernilai `normal`/`banned`/`deleted`/`unlist`.

### ☐ 2.3 Detail produk + verifikasi mapping — `GET /api/v2/product/get_item_base_info`

Ini uji mapping yang paling penting untuk produk. `map()` murni transformasi, tidak menyentuh DB.

```php
$svc = app(\Modules\Channel\Services\ShopeeProductService::class);
$raw = $svc->searchProductsPaged($shopee->shop_id, '', 0, 1);
$item = $raw['items'][0] ?? null;

$mapped = app(\Modules\Channel\Services\ShopeeToInternalProductMapper::class)
    ->map($item, $shopee->shop_id);

[
    'name'     => $mapped['name'] ?? null,
    'sku'      => $mapped['sku'] ?? null,
    'varian'   => count($mapped['variants'] ?? []),
    'media'    => count($mapped['media'] ?? []),
    'varian_0' => collect($mapped['variants'][0] ?? [])->only(['sku','sell_price'])->toArray(),
];
```

**Lolos bila:** `name`, `sku`, `variants[]` (beserta `sku` dan `sell_price`), dan `media[]` terisi — bukan null atau array kosong.

### ☐ 2.4 Stok live per model — `GET /api/v2/product/get_model_list`

```php
$m = \Modules\Channel\Models\ChannelShop::find($shopee->id)
    ->productMappings()->whereNotNull('external_product_id')->first();

app(\Modules\Channel\Services\ChannelLiveStockReader::class)
    ->read('shopee', $shopee->shop_id, $m->external_product_id);
```

**Lolos bila:** hasilnya `model_id => qty` dan angkanya sama dengan yang tampil di Seller Center.

### ☐ 2.5 Daftar order terbaru — `GET /api/v2/order/get_order_list`

```php
$sns = app(\Modules\Channel\Services\ShopeeOrderService::class)
    ->listRecentOrderIds($shopee->shop_id, now()->subDays(3)->timestamp);
[count($sns), array_slice($sns, 0, 5)];
```

### ☐ 2.6 Mapping order — `GET /api/v2/order/get_order_detail`

```php
$svc = app(\Modules\Channel\Services\ShopeeOrderService::class);
$ref = new \ReflectionMethod($svc, 'fetchOrderDetails');
$ref->setAccessible(true);
$orders = $ref->invoke($svc, $shopee, [$sns[0]]);

$mapped = app(\Modules\Channel\Services\ShopeeToInternalOrderMapper::class)
    ->map($orders[0], $shopee->shop_id);

collect($mapped)->except($PII)->toArray();

collect($mapped['items'] ?? [])
    ->map(fn ($i) => collect($i)->only(['sku','qty_in_base','price','amount'])->toArray())
    ->take(5)
    ->toArray();
```

**Lolos bila:** `channel_order_no` sama dengan `order_sn`, `grand_total` cocok dengan total di Seller Center, dan tiap item punya `sku` yang bisa ditemukan di master produk.

### ☐ 2.7 Logistik & alasan batal — `get_channel_list`, `get_shipping_parameter`

```php
$o = app(\Modules\Channel\Services\ShopeeOrderService::class);

[
    'kurir'    => $sum($run(fn () => $o->getLogistics($shopee->shop_id))),
    'instan'   => $sum($run(fn () => $o->instantChannelIds($shopee->shop_id))),
    'alasan'   => $sum($run(fn () => $o->getCancelReasons())),
    'tracking' => $sum($run(fn () => $o->getTrackingInfo($shopee->shop_id, $sns[0]))),
];
```

### ☐ 2.8 Keuangan — `GET /api/v2/payment/get_escrow_detail`

```php
$e = $run(fn () => app(\Modules\Channel\Services\ShopeeOrderService::class)
    ->getEscrowDetail($shopee->shop_id, $sns[0]));

isset($e['err'])
    ? 'ERR '.$e['err']
    : collect($e['ok']['order_income'] ?? [])
        ->only(['escrow_amount','order_selling_price','order_discounted_price','commission_fee','service_fee','buyer_total_amount'])
        ->toArray();
```

> Balikan mentah `get_escrow_detail` juga memuat `buyer_user_name` — jangan cetak utuh.

**Lolos bila:** nilai komisi/fee terisi dan `escrow_amount` masuk akal.

### ☐ 2.9 Detail paket — `GET /api/v2/order/get_package_detail`

```php
$d = $run(fn () => app(\Modules\Channel\Services\ShopeeOrderService::class)
    ->getPackageDetailByOrderSn($shopee->shop_id, $sns[0]));

isset($d['err'])
    ? 'ERR '.$d['err']
    : collect($d['ok']['response']['package_list'][0] ?? [])
        ->only(['package_number','logistics_status','shipping_carrier','parcel_chargeable_weight'])
        ->toArray();
```

**Lolos bila:** `package_number` terisi dan `logistics_status` konsisten dengan status di Seller Center.

> `getPackageDetailByOrderSn()` adalah satu-satunya jalur ke `get_package_detail`, dan ia memakai **GET** + `package_number_list` (satu nomor paket). Varian POST (`getPackageDetail(string $shopId, array $packageNumbers)`) sudah dihapus — verbnya ditolak Shopee dan method itu tidak pernah dipanggil dari mana pun.

### ☐ 2.10 Retur — `GET /api/v2/returns/get_return_list`, `get_return_detail`

```php
$o   = app(\Modules\Channel\Services\ShopeeOrderService::class);
$ret = $run(fn () => $o->listChannelReturns($shopee->shop_id, 1, 20))['ok'] ?? [];

['jml' => count($ret), 'contoh' => array_slice($ret, 0, 3)];
```

Lalu ambil detail satu retur. Balikan `fetchReturn*` sudah dinormalisasi service, bentuknya sama untuk Shopee, Lazada, dan TikTok:

```php
$sn = $ret[0]['return_sn'] ?? null;

$sn ? [
    'detail'   => collect($run(fn () => $o->fetchReturnDetail($shopee->shop_id, $sn))['ok'] ?? [])->except(['raw'])->toArray(),
    'tracking' => collect($run(fn () => $o->fetchReturnTracking($shopee->shop_id, $sn))['ok'] ?? [])->except(['raw'])->toArray(),
    'history'  => $sum($run(fn () => $o->fetchReturnHistory($shopee->shop_id, $sn))),
] : 'tidak ada retur pada rentang ini';
```

**Lolos bila:** `channel_status`, `reason_text`, dan `refund_amount` terisi. `tracking_number` wajar kosong kalau pembeli belum mengirim balik barangnya.

> Dua jebakan di sini. **Satu:** `except(['raw'])` bukan kosmetik — `raw` memuat payload mentah channel lengkap dengan data pembeli. **Dua:** `fetchReturnDetail`/`fetchReturnTracking` **menelan exception** dan mengembalikan struktur kosong (semua nilai `null`), jadi hasil serba-`null` berarti "gagal ambil", bukan "retur tanpa data" — cek Bagian 1.2 dulu.

---

## 3. Lazada

Adapter: `LazadaAdapter` · Service: `LazadaProductService`, `LazadaOrderService`

### ☐ 3.1 Status produk — `GET /products/get`

```php
$st = app(\Modules\Channel\Services\LazadaProductService::class)->fetchProductStatuses($lazada->shop_id);
[count($st), array_slice($st, 0, 3, true)];
```

> Kalau hasilnya array kosong, itu **bukan** berarti tidak ada produk — method ini mengembalikan `[]` juga saat toko/token tidak ketemu. Cek ulang Bagian 1.2.

### ☐ 3.2 Cari produk + mapping — `GET /products/get`

```php
$items = app(\Modules\Channel\Services\LazadaProductService::class)
    ->searchProducts($lazada->shop_id, '');

$mapped = app(\Modules\Channel\Services\LazadaToInternalProductMapper::class)
    ->map($items[0], $lazada->shop_id);

[
    'name'     => $mapped['name'] ?? null,
    'sku'      => $mapped['sku'] ?? null,
    'varian'   => count($mapped['variants'] ?? []),
    'media'    => count($mapped['media'] ?? []),
    'varian_0' => collect($mapped['variants'][0] ?? [])->only(['sku','sell_price'])->toArray(),
];
```

> `searchProducts('')` dengan kueri kosong akan menelusuri seluruh katalog sampai batas halaman maksimum. Untuk toko dengan ribuan SKU, ganti `''` dengan potongan nama produk yang Anda tahu ada — hasilnya sama untuk keperluan uji mapping, tapi jauh lebih ringan.

### ☐ 3.3 Produk live per item — `GET /product/item/get`

```php
$m = \Modules\Channel\Models\ChannelShop::find($lazada->id)
    ->productMappings()->whereNotNull('external_product_id')->first();

[
    'live_keys' => array_keys($run(fn () => app(\Modules\Channel\Services\LazadaProductService::class)
        ->fetchLiveProduct($lazada->shop_id, $m->external_product_id))['ok'] ?? []),
    'stok'      => $run(fn () => app(\Modules\Channel\Services\ChannelLiveStockReader::class)
        ->read('lazada', $lazada->shop_id, $m->external_product_id))['ok'] ?? 'ERR',
];
```

### ☐ 3.4 Pohon kategori — `GET /category/tree/get`

```php
$c = app(\Modules\Channel\Services\LazadaClient::class)
    ->request('GET', '/category/tree/get', [], $lazada->access_token);
count($c['data'] ?? []);
```

### ☐ 3.5 Daftar order — `GET /orders/get`

```php
$ids = app(\Modules\Channel\Services\LazadaOrderService::class)
    ->listRecentOrderIds($lazada->shop_id, now()->subDays(3)->toIso8601String());
[count($ids), array_slice($ids, 0, 5)];
```

### ☐ 3.6 Mapping order — `GET /order/get` + `GET /orders/items/get`

Perhatikan bentuk parameternya: `/orders/items/get` (jamak) menerima `order_ids` berupa **JSON array of string**, bukan `order_id` tunggal. Balikannya adalah daftar entri per order.

```php
$c = app(\Modules\Channel\Services\LazadaClient::class);

$order = $c->request('GET', '/order/get', ['order_id' => $ids[0]], $lazada->access_token)['data'] ?? [];

$res = $c->request(
    'GET',
    '/orders/items/get',
    ['order_ids' => json_encode([(string) $ids[0]])],
    $lazada->access_token
);
$items = $res['data'][0]['order_items'] ?? [];

$mapped = app(\Modules\Channel\Services\LazadaToInternalOrderMapper::class)
    ->map($order, $items, $lazada->shop_id);

collect($mapped)->except($PII)->toArray();
```

### ☐ 3.7 Tracking, kurir, alasan batal

```php
$o = app(\Modules\Channel\Services\LazadaOrderService::class);

[
    'trace'    => $sum($run(fn () => $o->getOrderTrace($lazada->shop_id, $ids[0]))),
    'kurir'    => $sum($run(fn () => $o->getShipmentProviders($lazada->shop_id))),
    'alasan'   => $sum($run(fn () => $o->getCancelReasons($lazada->shop_id))),
    'item_st'  => $sum($run(fn () => $o->itemStatuses($lazada->shop_id, $ids[0]))),
    'paket'    => $run(fn () => $o->resolvePackageIds($lazada->shop_id, $ids[0]))['ok'] ?? 'ERR',
];
```

### ☐ 3.8 Keuangan — `GET /finance/transaction/details/get`

```php
$t = $run(fn () => app(\Modules\Channel\Services\LazadaOrderService::class)
    ->getTransactionDetails($lazada->shop_id, $ids[0]));

isset($t['err'])
    ? 'ERR '.$t['err']
    : [
        'baris'  => count($t['ok']),
        'kolom'  => array_keys($t['ok'][0] ?? []),
        'contoh' => collect($t['ok'][0] ?? [])->only(['fee_name','amount','transaction_type'])->toArray(),
    ];
```

### ☐ 3.9 Retur — `GET /order/reverse/return/detail/list`, `/reverse/order/detail/get`

Lazada tidak punya method "daftar retur" di service kita — retur masuk lewat webhook/ingestion. Jadi ambil `reverse_order_id` dari data yang sudah tersimpan:

```php
$rid = \Modules\Sales\Models\SalesReturn::where('channel_shop_id', $lazada->id)
    ->whereNotNull('channel_return_id')
    ->latest()
    ->value('channel_return_id');

$o = app(\Modules\Channel\Services\LazadaOrderService::class);

$rid ? [
    'reverse_order_id' => $rid,
    'detail'   => collect($run(fn () => $o->fetchReturnDetail($lazada->shop_id, $rid))['ok'] ?? [])->except(['raw'])->toArray(),
    'tracking' => collect($run(fn () => $o->fetchReturnTracking($lazada->shop_id, $rid))['ok'] ?? [])->except(['raw'])->toArray(),
    'history'  => $sum($run(fn () => $o->fetchReturnHistory($lazada->shop_id, $rid))),
] : 'belum ada retur Lazada tersimpan';
```

**Lolos bila:** `channel_status` dan `reason_text` terisi. Kalau `$rid` kosong, itu temuan tersendiri — berarti belum ada retur Lazada yang pernah masuk, bukan kegagalan API.

---

## 4. TikTok Shop

Adapter: `TikTokAdapter` · Service: `TikTokProductService`, `TikTokOrderService`

> TikTok butuh `shop_cipher`. Kalau kosong, semua panggilan akan ditolak. Cek dulu: `$tiktok->shop_cipher`.

### ☐ 4.1 Status produk — `POST /product/202309/products/search`

```php
$st = app(\Modules\Channel\Services\TikTokProductService::class)->fetchProductStatuses($tiktok->shop_id);
[count($st), array_slice($st, 0, 3, true)];
```

### ☐ 4.2 Cari produk + mapping

```php
$items = app(\Modules\Channel\Services\TikTokProductService::class)
    ->searchProducts($tiktok->shop_id, '');

$mapped = app(\Modules\Channel\Services\TikTokToInternalProductMapper::class)
    ->map($items[0], $tiktok->shop_id);

[
    'name'     => $mapped['name'] ?? null,
    'sku'      => $mapped['sku'] ?? null,
    'varian'   => count($mapped['variants'] ?? []),
    'media'    => count($mapped['media'] ?? []),
    'varian_0' => collect($mapped['variants'][0] ?? [])->only(['sku','sell_price'])->toArray(),
];
```

> `searchProducts('')` dengan kueri kosong akan menelusuri seluruh katalog sampai batas halaman maksimum. Untuk toko dengan ribuan SKU, ganti `''` dengan potongan nama produk yang Anda tahu ada — hasilnya sama untuk keperluan uji mapping, tapi jauh lebih ringan.

### ☐ 4.3 Produk live — `GET /product/202309/products/{id}`

```php
$m = \Modules\Channel\Models\ChannelShop::find($tiktok->id)
    ->productMappings()->whereNotNull('external_product_id')->first();

[
    'live_keys' => array_keys($run(fn () => app(\Modules\Channel\Services\TikTokProductService::class)
        ->fetchLiveProduct($tiktok->shop_id, $m->external_product_id))['ok'] ?? []),
    'stok'      => $run(fn () => app(\Modules\Channel\Services\ChannelLiveStockReader::class)
        ->read('tiktok', $tiktok->shop_id, $m->external_product_id))['ok'] ?? 'ERR',
];
```

### ☐ 4.4 Mapping payload upload — tanpa mengirim apa pun

Ini cara paling aman memverifikasi mapping produk **keluar** (internal → TikTok). Murni membangun payload, tidak ada panggilan API tulis.

```php
$product = \Modules\Product\Models\Product::with(['variants','category','media'])
    ->whereHas('channelMappings', fn ($q) => $q->where('channel_shop_id', $tiktok->id))
    ->first();

app(\Modules\Channel\Services\TikTokProductService::class)
    ->buildUploadConfig($product, $tiktok);
```

**Lolos bila:** `category_id` terisi dan `sales_attribute_map` sesuai atribut kategori. Kalau melempar `RuntimeException` soal "bukan kategori paling spesifik", itu **temuan valid** — kategori produk perlu diperbaiki, bukan bug integrasi.

### ☐ 4.5 Daftar order — `POST /order/202309/orders/search`

```php
$ids = app(\Modules\Channel\Services\TikTokOrderService::class)
    ->listRecentOrderIds($tiktok->shop_id, 1);
[count($ids), array_slice($ids, 0, 5)];
```

### ☐ 4.6 Mapping order — `GET /order/202309/orders`

```php
$res = app(\Modules\Channel\Services\TikTokClient::class)->request(
    'GET',
    '/order/202309/orders',
    ['shop_cipher' => $tiktok->shop_cipher, 'ids' => $ids[0]],
    [],
    $tiktok->access_token
);

$mapped = app(\Modules\Channel\Services\TikTokToInternalOrderMapper::class)
    ->map($res['data']['orders'][0], $tiktok->shop_id);

collect($mapped)->except($PII)->toArray();
```

### ☐ 4.7 Paket & label — `GET /fulfillment/202309/packages/{id}`

```php
$o    = app(\Modules\Channel\Services\TikTokOrderService::class);
$pkgs = $run(fn () => $o->packageIdsForOrder($tiktok->shop_id, $ids[0]))['ok'] ?? [];

[
    'paket'    => $pkgs,
    'detail'   => array_keys($run(fn () => $o->getPackageDetail($tiktok->shop_id, $pkgs[0] ?? ''))['ok'] ?? []),
    'tracking' => $run(fn () => $o->resolveTrackingNumber($tiktok, $ids[0]))['ok'] ?? 'ERR',
];
```

> `getPackageDetail` memuat alamat penerima. Cetak `array_keys(...)` seperti di atas, bukan isinya.

### ☐ 4.8 Keuangan — `GET /finance/202309/statements`

```php
$o = app(\Modules\Channel\Services\TikTokOrderService::class);

[
    'statement_order' => array_keys($run(fn () => $o->getOrderStatement($tiktok->shop_id, $ids[0]))['ok'] ?? []),
    'statements'      => $sum($run(fn () => $o->getStatements($tiktok->shop_id, now()->subDays(30)->timestamp, now()->timestamp))),
    'payments'        => $sum($run(fn () => $o->getPayments($tiktok->shop_id, now()->subDays(30)->timestamp, now()->timestamp))),
];
```

### ☐ 4.9 Alasan batal & tolak retur

```php
$o = app(\Modules\Channel\Services\TikTokOrderService::class);

[
    'alasan_batal' => $sum($run(fn () => $o->getCancelReasonsLive($tiktok->shop_id))),
    'buyer_cancel' => $sum($run(fn () => $o->searchBuyerCancellation($tiktok->shop_id, $ids[0]))),
];
```

### ☐ 4.10 Retur — `POST /return_refund/202309/returns/search`, `returns/records`

`returns/search` memang POST, tapi murni pencarian — tidak mengubah apa pun. Retur bisa ditelusuri lewat `return_id` (dari data tersimpan) atau lewat `order_id`:

```php
$o = app(\Modules\Channel\Services\TikTokOrderService::class);

$rid = \Modules\Sales\Models\SalesReturn::where('channel_shop_id', $tiktok->id)
    ->whereNotNull('channel_return_id')
    ->latest()
    ->value('channel_return_id');

$rid ? [
    'return_id' => $rid,
    'detail'    => collect($run(fn () => $o->fetchReturnDetail($tiktok->shop_id, $rid))['ok'] ?? [])->except(['raw'])->toArray(),
    'tracking'  => collect($run(fn () => $o->fetchReturnTracking($tiktok->shop_id, $rid))['ok'] ?? [])->except(['raw'])->toArray(),
    'history'   => $sum($run(fn () => $o->fetchReturnHistory($tiktok->shop_id, $rid))),
] : 'belum ada retur TikTok tersimpan';
```

Kalau belum ada retur tersimpan, telusuri dari sisi pesanan — parameter ketiga menerima `order_id`:

```php
collect($run(fn () => $o->fetchReturnDetail($tiktok->shop_id, null, $ids[0]))['ok'] ?? [])
    ->except(['raw'])
    ->toArray();
```

**Lolos bila:** untuk pesanan yang memang punya retur, `channel_status` dan `refund_amount` terisi. Semua `null` berarti tidak ada retur untuk pesanan itu **atau** panggilan gagal diam-diam — bedakan dengan mengecek Bagian 1.2.

---

## 5. WooCommerce

Adapter: `WooCommerceAdapter` · Service: `WooCommerceProductService`, `WooCommerceOrderService`

> WooCommerce memakai `consumer_key`/`consumer_secret` + `store_url`, bukan OAuth token. Kalau `store_url` salah protokol (http vs https) atau ada trailing slash ganda, panggilan gagal dengan 401 yang menyesatkan.

### ☐ 5.1 Konektivitas dasar — `GET /wp-json/wc/v3/products`

```php
$p = $run(fn () => app(\Modules\Channel\Services\WooCommerceClient::class)
    ->get($woo, 'products', ['per_page' => 1, 'status' => 'publish']));

isset($p['err'])
    ? 'ERR '.$p['err']
    : ['jml' => count($p['ok']), 'contoh' => collect($p['ok'][0] ?? [])->only(['id','name','sku','status'])->toArray()];
```

**Lolos bila:** balikannya array produk, bukan `{"code":"woocommerce_rest_cannot_view"}`.

### ☐ 5.2 Cari produk + mapping

```php
$items = app(\Modules\Channel\Services\WooCommerceProductService::class)
    ->searchProducts($woo->shop_id, '');

$mapped = app(\Modules\Channel\Services\WooCommerceToInternalProductMapper::class)
    ->map($items[0], $woo->shop_id);

[
    'name'     => $mapped['name'] ?? null,
    'sku'      => $mapped['sku'] ?? null,
    'varian'   => count($mapped['variants'] ?? []),
    'media'    => count($mapped['media'] ?? []),
    'varian_0' => collect($mapped['variants'][0] ?? [])->only(['sku','sell_price'])->toArray(),
];
```

### ☐ 5.3 Variasi produk — `GET /products/{id}/variations`

```php
$v = $run(fn () => app(\Modules\Channel\Services\WooCommerceClient::class)
    ->paginate($woo, "products/{$items[0]['id']}/variations"));

isset($v['err'])
    ? 'ERR '.$v['err']
    : ['jml' => count($v['ok']), 'contoh' => collect($v['ok'][0] ?? [])->only(['id','sku','price','stock_quantity'])->toArray()];
```

### ☐ 5.4 Daftar & mapping order — `GET /orders`

```php
$orders = app(\Modules\Channel\Services\WooCommerceClient::class)
    ->paginate($woo, 'orders', ['after' => now()->subDays(3)->toIso8601String()], 20, 1);

count($orders);

$mapped = app(\Modules\Channel\Services\WooCommerceToInternalOrderMapper::class)
    ->map($orders[0], $woo->shop_id);

collect($mapped)->except($PII)->toArray();
```

### ☐ 5.5 Webhook terdaftar — `GET /webhooks`

```php
$w = $run(fn () => app(\Modules\Channel\Services\WooCommerceClient::class)->get($woo, 'webhooks'));

[
    'di_toko'    => collect($w['ok'] ?? [])->map(fn ($h) => [$h['id'] ?? null, $h['topic'] ?? null, $h['status'] ?? null])->toArray(),
    'tersimpan'  => $woo->external_webhook_ids,
    'err'        => $w['err'] ?? null,
];
```

**Lolos bila:** webhook yang terdaftar di toko cocok dengan `external_webhook_ids` yang tersimpan.

### ☐ 5.6 Refund — `GET /orders/{id}/refunds`

WooCommerce tidak punya konsep "retur" seperti marketplace, dan tidak ada service khusus untuk itu di kode kita — yang tersedia hanya refund per pesanan, dibaca langsung lewat client:

```php
$oid = $orders[0]['id'] ?? null;

$rf = $run(fn () => app(\Modules\Channel\Services\WooCommerceClient::class)
    ->get($woo, "orders/{$oid}/refunds"));

isset($rf['err'])
    ? 'ERR '.$rf['err']
    : ['jml' => count($rf['ok']), 'contoh' => collect($rf['ok'][0] ?? [])->only(['id','amount','reason','date_created'])->toArray()];
```

**Lolos bila:** endpoint menjawab `200` dengan array (boleh kosong). Kalau `403`, consumer key-nya read-only untuk resource ini — bukan bug integrasi.

> WooCommerce **tidak** punya topik webhook untuk refund, jadi refund tidak pernah masuk otomatis. Ketiadaan data refund di sistem kita bukan indikasi kegagalan sinkronisasi.

---

## 6. Verifikasi mapping di sisi database

Bagian ini murni query lokal — tidak menyentuh API marketplace sama sekali. Aman dijalankan kapan saja.

### ☐ 6.1 Ringkasan mapping produk per toko

```php
\Modules\Product\Models\ProductChannelMapping::query()
    ->selectRaw('channel_shop_id, sync_status, count(*) as jml')
    ->groupBy('channel_shop_id', 'sync_status')
    ->get()
    ->groupBy('channel_shop_id')
    ->map(fn ($rows) => $rows->pluck('jml', 'sync_status'))
    ->toArray();
```

### ☐ 6.2 Mapping tanpa `external_product_id` (mapping menggantung)

```php
\Modules\Product\Models\ProductChannelMapping::whereNull('external_product_id')
    ->orWhere('external_product_id', '')
    ->count();
```

**Lolos bila:** `0`, atau semuanya berstatus `pending`/`failed`. Mapping `synced` tanpa external id adalah **inkonsistensi nyata**.

### ☐ 6.3 Mapping varian yatim

```php
\Modules\Product\Models\ProductVariantChannelMapping::query()
    ->whereDoesntHave('channelMapping')
    ->count();

\Modules\Product\Models\ProductVariantChannelMapping::query()
    ->whereDoesntHave('variant')
    ->count();
```

**Lolos bila:** dua-duanya `0`.

### ☐ 6.4 Varian tanpa `external_sku_id`

```php
\Modules\Product\Models\ProductVariantChannelMapping::query()
    ->whereNull('external_sku_id')
    ->with('channelMapping:id,channel_shop_id,sync_status')
    ->get(['id','product_channel_mapping_id','variant_id'])
    ->groupBy(fn ($m) => $m->channelMapping?->sync_status)
    ->map->count();
```

Varian `synced` tanpa `external_sku_id` berarti sinkron stok akan gagal diam-diam untuk varian itu.

### ☐ 6.5 Order tanpa toko yang bisa dikenali

```php
\Modules\Sales\Models\SalesOrder::query()
    ->whereNotNull('channel_order_no')
    ->whereNull('channel_shop_id')
    ->count();
```

**Lolos bila:** `0`.

### ☐ 6.6 Item order yang belum terpetakan ke master

```php
\Modules\Sales\Models\SalesOrderItem::query()
    ->whereNull('item_id')
    ->whereNotNull('channel_product_id')
    ->count();
```

Angka besar di sini berarti banyak thumbnail dan laporan HPP akan kosong.

### ☐ 6.7 Konsistensi mapping vs listing live

Membandingkan `synced_stock` yang tersimpan dengan stok yang benar-benar tayang. Ini panggilan API — jalankan untuk beberapa produk saja, jangan seluruh katalog.

```php
$m = \Modules\Product\Models\ProductChannelMapping::with('channelShop.channel')
    ->whereNotNull('external_product_id')
    ->where('sync_status', 'synced')
    ->first();

$live = app(\Modules\Channel\Services\ChannelLiveStockReader::class)->read(
    $m->channelShop->channel->code,
    $m->channelShop->shop_id,
    $m->external_product_id
);

$tersimpan = \Modules\Product\Models\ProductVariantChannelMapping::where('product_channel_mapping_id', $m->id)
    ->pluck('synced_stock', 'external_sku_id');

['live' => $live, 'tersimpan' => $tersimpan->toArray()];
```

**Lolos bila:** kunci di kedua sisi cocok dan selisih qty bisa dijelaskan oleh `stock_push_buffer`.

---

## 7. Rekap hasil

| # | Uji | Shopee | Lazada | TikTok | Woo |
|---|---|---|---|---|---|
| 1 | Token & koneksi (1.2) | ☐ | ☐ | ☐ | ☐ |
| 2 | Daftar / status produk | ☐ | ☐ | ☐ | ☐ |
| 3 | Mapping produk masuk | ☐ | ☐ | ☐ | ☐ |
| 4 | Stok live per varian | ☐ | ☐ | ☐ | — |
| 5 | Daftar order | ☐ | ☐ | ☐ | ☐ |
| 6 | Mapping order masuk | ☐ | ☐ | ☐ | ☐ |
| 7 | Tracking / logistik | ☐ | ☐ | ☐ | — |
| 8 | Detail paket | ☐ | ☐ | ☐ | — |
| 9 | Data keuangan | ☐ | ☐ | ☐ | — |
| 10 | Retur / refund | ☐ | ☐ | ☐ | ☐ |
| 11 | Integritas mapping DB (6.1–6.6) | ☐ | ☐ | ☐ | ☐ |

Tanda `—` berarti channel tersebut memang tidak menyediakan endpoint itu, bukan gagal uji.

---

## 8. Endpoint yang MENULIS — jangan dijalankan saat verifikasi

Daftar ini ada supaya tidak ada yang tidak sengaja terpanggil. Semuanya mengubah data produksi, di sistem kita maupun di marketplace.

**Menulis ke database kita:**

| Method | Efek |
|---|---|
| `*ProductService::pullProducts()` | impor massal produk + buat/ubah mapping |
| `*ProductService::pullProductById()` | impor satu produk |
| `*ProductService::downloadProductIds()` | impor batch |
| `*ProductService::reconcileChannelData()` | menimpa data mapping |
| `*ProductService::syncCategoryTree()` / `syncCategoryAttributes()` | menimpa kategori & atribut channel |
| `*OrderService::pullOrders()` / `pullOrderById()` | membuat sales order + memotong stok |
| `ChannelDownloadService::download()` / `downloadBulk()` / `pull()` | seluruh alur impor |

**Memanggil API tulis marketplace — dampaknya terlihat pembeli:**

| Method | Efek |
|---|---|
| `pushProduct` / `pushUpdate` / `updateProduct` | membuat atau mengubah listing |
| `deleteProduct` / `activateProduct` / `deactivateProduct` | menghapus atau menurunkan listing |
| `syncPriceAndStock` / `syncStock` / `syncInventoryBySku` | mengubah harga & stok tayang |
| `shipOrder` / `massShipOrder` / `readyToShip` / `fulfillPack` / `acceptOrder` | menggerakkan status pesanan |
| `cancelOrder` / `declineOrder` / `handleBuyerCancellation` | membatalkan pesanan |
| `approveReturn` / `rejectReturn` / `confirmReturn` / `disputeReturn` | memproses retur |
| `createShippingDocument` / `printAwb` / `getAirwayBill` | mencetak resi (mengunci nomor resi) |
| `boostItem` | memakai kuota boost harian |

> `getAirwayBill` dan `printAwb` terlihat seperti "get", tapi di Shopee dan Lazada keduanya memicu pembuatan dokumen di sisi marketplace. Perlakukan sebagai operasi tulis.

---

## 9. Kalau ada yang gagal

| Gejala | Penyebab paling sering |
|---|---|
| `Toko ... tidak ditemukan atau belum terhubung` | memberi UUID `channel_shops.id`, bukan `shop_id` eksternal (lihat 0.5) |
| `fetchProductStatuses` mengembalikan `[]` | toko/token tidak ketemu — method ini menelan error, cek 1.2 dulu |
| `fetchReturnDetail`/`fetchReturnTracking` semua `null` | method ini menelan exception dan mengembalikan struktur kosong — bisa berarti retur tidak ada **atau** panggilan gagal; cek 1.2 |
| Shopee `get_package_detail` menjawab 404 | endpoint ini hanya menerima **GET** — pakai `getPackageDetailByOrderSn` (lihat 2.9) |
| `pullOrders` mengembalikan `0` tanpa panggilan API | sinkronisasi global sedang di-pause (lihat 0.5) |
| `PHP Parse error: Cannot use try without catch or finally` | `try/catch` dipecah ke banyak baris lewat stdin — bungkus satu baris (lihat 0.3) |
| `Undefined variable $shopee` / `$run` / `$PII` | blok dijalankan sebagai heredoc terpisah; variabel Bagian 1.3 tidak lintas-proses (lihat 0.2) |
| `kubectl get pods` mengembalikan `No resources found` | salah label/namespace — yang benar `-n cilupbah -l app=cilupbah-app` (lihat 0.1) |
| Hasil tidak berubah walau fix sudah di-merge | pod menjalankan image lama — cek rollout (lihat 0.5 poin Empat) |
| TikTok menolak semua panggilan | `shop_cipher` kosong — perlu otorisasi ulang |
| WooCommerce 401 | `store_url` salah protokol atau consumer key/secret tidak cocok |
| `TokenExpiredException` berulang | refresh token juga kedaluwarsa — perlu otorisasi ulang toko |
| Mapping produk balikannya `variants` kosong | produk di marketplace tidak bervariasi, atau struktur respons berubah setelah update API |
