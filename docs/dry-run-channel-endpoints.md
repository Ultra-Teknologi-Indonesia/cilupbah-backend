# Dry Run Endpoint Channel — Verifikasi Data & Mapping

Checklist verifikasi manual untuk semua channel yang terintegrasi (**Shopee, Lazada, TikTok, WooCommerce**), dijalankan lewat `php artisan tinker` di pod produksi.

Tujuannya satu: memastikan **data berhasil diambil dari marketplace** dan **mapping ke model internal sudah benar**, tanpa mengubah apa pun.

> **Aturan mutlak untuk dokumen ini.** Hanya perintah di Bagian 1–7 yang boleh dijalankan di produksi. Bagian 8 adalah daftar endpoint yang **menulis** — dicantumkan supaya Anda tahu mana yang harus dihindari, bukan untuk dijalankan.

---

## 0. Persiapan

### 0.1 Temukan pod

```bash
kubectl get pods -n <namespace> -l app=cilupbah-backend
kubectl get deploy -n <namespace>
```

Simpan sebagai variabel supaya perintah berikutnya pendek:

```bash
export NS=<namespace>
export APP=deploy/<nama-deployment>
```

### 0.2 Masuk ke tinker

Mode interaktif (dipakai untuk sebagian besar checklist di bawah — tempel blok PHP-nya satu per satu):

```bash
kubectl exec -it -n $NS $APP -- php artisan tinker
```

Mode sekali jalan (untuk perintah pendek, hasilnya bisa di-pipe/disimpan):

```bash
kubectl exec -i -n $NS $APP -- php artisan tinker --execute="dump(\Modules\Channel\Models\Channel::pluck('code'));"
```

> Kalau pod punya beberapa container, tambahkan `-c <container>`.

### 0.3 Tiga hal yang wajib dipahami sebelum mulai

**Satu.** Hampir semua service channel menerima **`shop_id` eksternal** (kolom `channel_shops.shop_id`), **bukan** UUID `channel_shops.id`. Salah satu yang paling sering bikin bingung — `requireShop()` melakukan `findByShopId()`, jadi memberi UUID akan selalu melempar "Toko tidak ditemukan".

**Dua.** Panggilan read-only tetap bisa menulis satu hal: **refresh token**. Kalau access token sudah kedaluwarsa, service akan otomatis refresh dan menyimpan token baru ke `channel_shops`. Ini efek samping yang wajar dan justru diinginkan — tapi sadari bahwa DB tersentuh.

**Tiga.** Kalau sinkronisasi global sedang di-pause, `pullOrders()` diam-diam mengembalikan `0` tanpa memanggil API sama sekali. Cek dulu supaya Anda tidak salah menyimpulkan "API mati":

```php
app(\Modules\Channel\Services\ChannelSyncSettingService::class)->isPaused();
```

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
        'error'         => $s->last_error,
    ])
    ->toArray();
```

**Lolos bila:** `sisa_menit` positif untuk semua toko aktif, `error` kosong, dan tidak ada `disconnected_at`.

Kalau `sisa_menit` negatif atau mendekati nol, refresh dulu (ini **menulis token**, tapi aman dan memang perlu):

```php
$shop = \Modules\Channel\Models\ChannelShop::where('shop_id', '<SHOP_ID>')->first();
app(\Modules\Channel\Services\ShopeeAuthService::class)->refreshStoreToken($shop->id);
```

Ganti `ShopeeAuthService` sesuai channel: `LazadaAuthService`, `TikTokAuthService`. WooCommerce memakai consumer key/secret dan tidak punya refresh token.

### ☐ 1.3 Siapkan variabel kerja

Semua blok berikutnya memakai variabel ini. Set sekali di awal sesi tinker.

```php
$shopee = \Modules\Channel\Models\ChannelShop::whereHas('channel', fn ($q) => $q->where('code','shopee'))->where('is_active', true)->first();
$lazada = \Modules\Channel\Models\ChannelShop::whereHas('channel', fn ($q) => $q->where('code','lazada'))->where('is_active', true)->first();
$tiktok = \Modules\Channel\Models\ChannelShop::whereHas('channel', fn ($q) => $q->where('code','tiktok'))->where('is_active', true)->first();
$woo    = \Modules\Channel\Models\ChannelShop::whereHas('channel', fn ($q) => $q->where('code','woocommerce'))->where('is_active', true)->first();

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

$mapped;
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

collect($mapped)->except(['items'])->toArray();
$mapped['items'] ?? [];
```

**Lolos bila:** `channel_order_no` sama dengan `order_sn`, `grand_total` cocok dengan total di Seller Center, dan tiap item punya `sku` yang bisa ditemukan di master produk.

### ☐ 2.7 Logistik & alasan batal — `get_channel_list`, `get_shipping_parameter`

```php
$o = app(\Modules\Channel\Services\ShopeeOrderService::class);
$o->getLogistics($shopee->shop_id);
$o->instantChannelIds($shopee->shop_id);
$o->getCancelReasons();
$o->getTrackingInfo($shopee->shop_id, $sns[0]);
```

### ☐ 2.8 Keuangan — `GET /api/v2/payment/get_escrow_detail`

```php
app(\Modules\Channel\Services\ShopeeOrderService::class)
    ->getEscrowDetail($shopee->shop_id, $sns[0]);
```

**Lolos bila:** nilai komisi/fee terisi dan `escrow_amount` masuk akal.

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

$mapped;
```

> `searchProducts('')` dengan kueri kosong akan menelusuri seluruh katalog sampai batas halaman maksimum. Untuk toko dengan ribuan SKU, ganti `''` dengan potongan nama produk yang Anda tahu ada — hasilnya sama untuk keperluan uji mapping, tapi jauh lebih ringan.

### ☐ 3.3 Produk live per item — `GET /product/item/get`

```php
$m = \Modules\Channel\Models\ChannelShop::find($lazada->id)
    ->productMappings()->whereNotNull('external_product_id')->first();

app(\Modules\Channel\Services\LazadaProductService::class)
    ->fetchLiveProduct($lazada->shop_id, $m->external_product_id);

app(\Modules\Channel\Services\ChannelLiveStockReader::class)
    ->read('lazada', $lazada->shop_id, $m->external_product_id);
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

collect($mapped)->except(['items'])->toArray();
```

### ☐ 3.7 Tracking, kurir, alasan batal

```php
$o = app(\Modules\Channel\Services\LazadaOrderService::class);
$o->getOrderTrace($lazada->shop_id, $ids[0]);
$o->getShipmentProviders($lazada->shop_id);
$o->getCancelReasons($lazada->shop_id);
$o->itemStatuses($lazada->shop_id, $ids[0]);
$o->resolvePackageIds($lazada->shop_id, $ids[0]);
```

### ☐ 3.8 Keuangan — `GET /finance/transaction/details/get`

```php
app(\Modules\Channel\Services\LazadaOrderService::class)
    ->getTransactionDetails($lazada->shop_id, $ids[0]);
```

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

$mapped;
```

> `searchProducts('')` dengan kueri kosong akan menelusuri seluruh katalog sampai batas halaman maksimum. Untuk toko dengan ribuan SKU, ganti `''` dengan potongan nama produk yang Anda tahu ada — hasilnya sama untuk keperluan uji mapping, tapi jauh lebih ringan.

### ☐ 4.3 Produk live — `GET /product/202309/products/{id}`

```php
$m = \Modules\Channel\Models\ChannelShop::find($tiktok->id)
    ->productMappings()->whereNotNull('external_product_id')->first();

app(\Modules\Channel\Services\TikTokProductService::class)
    ->fetchLiveProduct($tiktok->shop_id, $m->external_product_id);

app(\Modules\Channel\Services\ChannelLiveStockReader::class)
    ->read('tiktok', $tiktok->shop_id, $m->external_product_id);
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

collect($mapped)->except(['items'])->toArray();
```

### ☐ 4.7 Paket & label — `GET /fulfillment/202309/packages/{id}`

```php
$o = app(\Modules\Channel\Services\TikTokOrderService::class);
$pkgs = $o->packageIdsForOrder($tiktok->shop_id, $ids[0]);
$pkgs;
$o->getPackageDetail($tiktok->shop_id, $pkgs[0] ?? '');
$o->resolveTrackingNumber($tiktok, $ids[0]);
```

### ☐ 4.8 Keuangan — `GET /finance/202309/statements`

```php
$o = app(\Modules\Channel\Services\TikTokOrderService::class);
$o->getOrderStatement($tiktok->shop_id, $ids[0]);
$o->getStatements($tiktok->shop_id, now()->subDays(30)->timestamp, now()->timestamp);
$o->getPayments($tiktok->shop_id, now()->subDays(30)->timestamp, now()->timestamp);
```

### ☐ 4.9 Alasan batal & tolak retur

```php
$o = app(\Modules\Channel\Services\TikTokOrderService::class);
$o->getCancelReasonsLive($tiktok->shop_id);
$o->searchBuyerCancellation($tiktok->shop_id, $ids[0]);
```

---

## 5. WooCommerce

Adapter: `WooCommerceAdapter` · Service: `WooCommerceProductService`, `WooCommerceOrderService`

> WooCommerce memakai `consumer_key`/`consumer_secret` + `store_url`, bukan OAuth token. Kalau `store_url` salah protokol (http vs https) atau ada trailing slash ganda, panggilan gagal dengan 401 yang menyesatkan.

### ☐ 5.1 Konektivitas dasar — `GET /wp-json/wc/v3/products`

```php
app(\Modules\Channel\Services\WooCommerceClient::class)
    ->get($woo, 'products', ['per_page' => 1, 'status' => 'publish']);
```

**Lolos bila:** balikannya array produk, bukan `{"code":"woocommerce_rest_cannot_view"}`.

### ☐ 5.2 Cari produk + mapping

```php
$items = app(\Modules\Channel\Services\WooCommerceProductService::class)
    ->searchProducts($woo->shop_id, '');

$mapped = app(\Modules\Channel\Services\WooCommerceToInternalProductMapper::class)
    ->map($items[0], $woo->shop_id);

$mapped;
```

### ☐ 5.3 Variasi produk — `GET /products/{id}/variations`

```php
app(\Modules\Channel\Services\WooCommerceClient::class)
    ->paginate($woo, "products/{$items[0]['id']}/variations");
```

### ☐ 5.4 Daftar & mapping order — `GET /orders`

```php
$orders = app(\Modules\Channel\Services\WooCommerceClient::class)
    ->paginate($woo, 'orders', ['after' => now()->subDays(3)->toIso8601String()], 20, 1);

count($orders);

$mapped = app(\Modules\Channel\Services\WooCommerceToInternalOrderMapper::class)
    ->map($orders[0], $woo->shop_id);

collect($mapped)->except(['items'])->toArray();
```

### ☐ 5.5 Webhook terdaftar — `GET /webhooks`

```php
app(\Modules\Channel\Services\WooCommerceClient::class)->get($woo, 'webhooks');
$woo->external_webhook_ids;
```

**Lolos bila:** webhook yang terdaftar di toko cocok dengan `external_webhook_ids` yang tersimpan.

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
| 8 | Data keuangan | ☐ | ☐ | ☐ | — |
| 9 | Integritas mapping DB (6.1–6.6) | ☐ | ☐ | ☐ | ☐ |

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
| `Toko ... tidak ditemukan atau belum terhubung` | memberi UUID `channel_shops.id`, bukan `shop_id` eksternal (lihat 0.3) |
| `fetchProductStatuses` mengembalikan `[]` | toko/token tidak ketemu — method ini menelan error, cek 1.2 dulu |
| `pullOrders` mengembalikan `0` tanpa panggilan API | sinkronisasi global sedang di-pause (lihat 0.3) |
| TikTok menolak semua panggilan | `shop_cipher` kosong — perlu otorisasi ulang |
| WooCommerce 401 | `store_url` salah protokol atau consumer key/secret tidak cocok |
| `TokenExpiredException` berulang | refresh token juga kedaluwarsa — perlu otorisasi ulang toko |
| Mapping produk balikannya `variants` kosong | produk di marketplace tidak bervariasi, atau struktur respons berubah setelah update API |
