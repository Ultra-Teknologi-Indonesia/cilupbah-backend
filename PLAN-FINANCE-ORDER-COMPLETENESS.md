# PLAN — Kelengkapan Data Keuangan Penjualan (Order Finance)

> Tujuan: setiap `sales_order` punya data keuangan **lengkap dan akurat** — subtotal, diskon (seller + platform), pajak, ongkir, **biaya admin/komisi/layanan**, dan **nilai bersih yang diterima seller (settlement/escrow)** — untuk TikTok Shop & Shopee.
>
> Prinsip wajib: **tidak mengubah business-flow yang sudah ada** (stok, status, picklist, settlement modul). Semua perubahan finance bersifat **aditif** (kolom baru nullable + jalur sync terpisah). Tidak ada bug-fix/refactor di luar scope ini.

---

## 1. Kondisi Saat Ini (hasil audit kode)

### Yang sudah disimpan di `sales_orders`
`sub_total`, `total_disc` (lumped), `total_tax`, `shipping_cost`, `actual_shipping_fee`, `insurance_cost`, `grand_total`.

### Yang HILANG / belum diambil
| Data | TikTok | Shopee |
|---|---|---|
| Voucher seller | ✅ disimpan (`seller_discount` → `total_disc`) | ❌ di-set `0` |
| Voucher platform | ❌ ada di payload (`payment.platform_discount`), **dibuang** | ❌ butuh escrow |
| Komisi platform | ❌ butuh Finance API | ❌ butuh escrow |
| Biaya layanan / transaksi | ❌ butuh Finance API | ❌ butuh escrow |
| Komisi afiliasi | ❌ butuh Finance API | ❌ (n/a / escrow) |
| Net settlement (diterima seller) | ❌ butuh Finance API | ❌ butuh escrow |

### Fakta penting dari kode
- **Tidak ada** pemanggilan escrow / finance / settlement di mana pun (`grep` kosong).
- `ShopeeOrderService::DETAIL_FIELDS` (baris 13) **tidak** memuat field finansial — Shopee memang menaruhnya di endpoint **terpisah** `/api/v2/payment/get_escrow_detail`.
- `TikTokToInternalOrderMapper` (baris 82–88) hanya memetakan sebagian `payment` object.
- `upsertFromChannel()` menulis order via `$fillable` model + `reconcileStockTransition()`. **Kolom baru harus masuk `$fillable`** agar tersimpan.
- `resolveInternalStatus()` sudah mencegah status mundur — aman untuk re-sync data finance belakangan.

---

## 2. Keputusan Desain (anti business-flow error)

1. **Estimasi vs Final dipisah secara eksplisit.**
   - Saat order ditarik (create/update), fee yang ada di order-detail payload masih **ESTIMASI** (mis. TikTok `platform_discount`).
   - Angka **FINAL** (komisi, biaya admin, net) baru tersedia setelah order **settle** (escrow released / statement terbit).
   - → Tambah flag `is_settled` (bool) + `finance_synced_at` (timestamp). Jangan pernah menampilkan fee final sebelum `is_settled = true`.

2. **Jalur sync finance TERPISAH dari sync order.**
   - Tambah method khusus `SalesOrderService::updateOrderFinance($salesorderNo, array $finance)` yang **hanya** meng-update kolom finance. **Tidak** menyentuh `status`, stok, items, atau location.
   - Mencegah data escrow yang datang belakangan menimpa/menggeser business-flow.

3. **Tidak mengubah `total_disc` legacy.** Tetap dipertahankan untuk backward-compat. Kolom baru `seller_voucher` + `platform_voucher` adalah breakdown; `total_disc` boleh tetap = total diskon yang ditanggung seller.

4. **Audit trail mentah disimpan** (Phase 3, opsional tapi disarankan) di tabel `sales_order_fee_lines` agar tidak ada data channel yang hilang & bisa rekonsiliasi per baris.

5. **Idempoten & aman re-run.** Escrow/finance boleh ditarik berkali-kali; update bersifat overwrite kolom finance saja. Trigger pakai job + nightly sweep (bukan hanya webhook) supaya tidak ada order yang terlewat.

6. **Presisi uang.** Ikuti pola eksisting `decimal(18,4)` (seperti `actual_shipping_fee`) untuk semua kolom fee baru. Casting konsisten di model `$casts`.

---

## 3. Skema Database

### 3.1 Migration: kolom finance di `sales_orders` (Phase 1)
```
seller_voucher          decimal(18,4) nullable   // diskon ditanggung seller
platform_voucher        decimal(18,4) nullable   // diskon ditanggung platform/marketplace
commission_fee          decimal(18,4) nullable   // komisi platform
service_fee             decimal(18,4) nullable   // biaya layanan
transaction_fee         decimal(18,4) nullable   // biaya transaksi/payment
affiliate_commission    decimal(18,4) nullable   // komisi afiliasi (TikTok), nullable
seller_shipping_borne   decimal(18,4) nullable   // ongkir bersih ditanggung seller
platform_shipping_rebate decimal(18,4) nullable  // subsidi ongkir dari platform
settlement_amount       decimal(18,4) nullable   // NET diterima seller (escrow_amount / settlement)
fee_currency            string(8) default 'IDR'
is_settled              boolean default false     // true jika fee final dari escrow/finance
finance_synced_at       timestamp nullable        // kapan data final ditarik
```
- Tambahkan semua kolom ke `SalesOrder::$fillable` dan `$casts` (decimal→string/float, bool, datetime).

### 3.2 Migration: `sales_order_fee_lines` (Phase 3, audit mentah)
```
id uuid pk
order_id uuid  -> sales_orders.id (cascade)
fee_type string(50)        // 'commission', 'service_fee', 'platform_voucher', dst (kanonik)
channel_fee_code string(80)// nama field asli dari channel (audit)
amount decimal(18,4)
is_settled boolean
source string(20)          // 'tiktok' | 'shopee'
created_at / updated_at
index(order_id), index(fee_type)
```

---

## 4. Pemetaan Field per Channel

### 4.1 TikTok
| Kanonik internal | Sumber payload | Kapan tersedia |
|---|---|---|
| `seller_voucher` | `payment.seller_discount` | order detail (estimasi) |
| `platform_voucher` | `payment.platform_discount` | order detail (estimasi) — **quick win** |
| `total_tax` | `payment.tax` | order detail |
| `commission_fee` | Finance API → statement (`*_commission` / `platform_commission`) | setelah settle |
| `service_fee` | Finance API → statement | setelah settle |
| `affiliate_commission` | Finance API → statement | setelah settle |
| `settlement_amount` | Finance API → `settlement_amount` / `revenue` | setelah settle |

> Endpoint TikTok Finance: **Get Order Statement Transactions** (mis. `GET /finance/202501/orders/{order_id}/statement_transactions`). **Versi/path persis WAJIB diverifikasi** ke dokumentasi TikTok Partner aktif sebelum implementasi — jangan hardcode tanpa cek.

### 4.2 Shopee — `GET /api/v2/payment/get_escrow_detail?order_sn=...`
Field di `response.order_income`:
| Kanonik internal | Field escrow |
|---|---|
| `seller_voucher` | `voucher_from_seller` |
| `platform_voucher` | `voucher_from_shopee` |
| `commission_fee` | `commission_fee` |
| `service_fee` | `service_fee` |
| `transaction_fee` | `seller_transaction_fee` |
| `seller_shipping_borne` | `actual_shipping_fee` − `buyer_paid_shipping_fee` (+ `shopee_shipping_rebate`) |
| `platform_shipping_rebate` | `shopee_shipping_rebate` |
| `settlement_amount` | `escrow_amount` |

> Semua di Shopee bersifat **final saat order `COMPLETED`** (escrow released). Sebelum itu sebagian field bisa kosong/estimasi.

---

## 5. Trigger & Orkestrasi Sync

### 5.1 Quick win (tanpa API baru) — Phase 1
- TikTok mapper: tambahkan `platform_voucher = payment.platform_discount`, `seller_voucher = payment.seller_discount`. Tersimpan saat pull order biasa. `is_settled` tetap `false`.

### 5.2 Penarikan data final — Phase 2
Dua jalur, keduanya memanggil `updateOrderFinance()`:
1. **Event-driven:** saat order mencapai status final (`COMPLETED`/`shipped`+settled) lewat webhook/pull, dispatch `SyncOrderFinanceJob(orderId)` ke queue `channel_sync`.
2. **Nightly reconciliation sweep:** scheduled command `orders:sync-finance` — scan order `COMPLETED` dengan `is_settled = false` (atau `finance_synced_at` null) dalam N hari terakhir, lalu tarik escrow/finance. Ini jaring pengaman agar tidak ada order terlewat kalau webhook miss.

`SyncOrderFinanceJob`:
- Shopee → `ShopeeOrderService::getEscrowDetail($shopId, $orderSn)` (method baru) → map → `updateOrderFinance`.
- TikTok → `TikTokOrderService::getOrderStatement($shopId, $orderId)` (method baru) → map → `updateOrderFinance`.
- Set `is_settled = true`, `finance_synced_at = now()` hanya jika data final valid.
- Idempoten; aman retry. Gagal → log + biarkan sweep berikutnya mencoba lagi.

---

## 6. Eksposur API & FE

1. **`SalesOrderResource`**: tambah blok `finance`:
   ```
   finance: {
     seller_voucher, platform_voucher, commission_fee, service_fee,
     transaction_fee, affiliate_commission, seller_shipping_borne,
     platform_shipping_rebate, settlement_amount, currency, is_settled,
     synced_at
   }
   ```
   Cast `(float)`; null tetap null (jangan paksa 0 supaya beda "belum settle" vs "nol").
2. **OpenAPI schema** di-update sesuai blok baru.
3. **FE**: di detail order tampilkan rincian biaya + badge "Estimasi / Final (settled)". Kolom net settlement hanya muncul saat `is_settled = true`.

---

## 7. Urutan Eksekusi (fase, bisa di-PR terpisah)

- [ ] **Phase 0 — Verifikasi API**: konfirmasi path/versi TikTok Finance API & field `get_escrow_detail` Shopee di sandbox. (blok sebelum Phase 2)
- [ ] **Phase 1 — Skema + quick win**:
  - [ ] Migration kolom finance di `sales_orders` + `$fillable` + `$casts`.
  - [ ] TikTok mapper: map `platform_voucher` + `seller_voucher`.
  - [ ] `SalesOrderResource` + OpenAPI: blok `finance`.
- [ ] **Phase 2 — Penarikan final**:
  - [ ] `SalesOrderService::updateOrderFinance()` (hanya kolom finance, no stok/status).
  - [ ] Shopee `getEscrowDetail()` + mapper escrow.
  - [ ] TikTok `getOrderStatement()` + mapper statement.
  - [ ] `SyncOrderFinanceJob` + dispatch saat order completed.
  - [ ] Command `orders:sync-finance` + schedule (nightly).
- [ ] **Phase 3 — Audit & FE**:
  - [ ] Tabel `sales_order_fee_lines` + penulisan baris saat sync (opsional, disarankan).
  - [ ] FE detail order: rincian biaya + badge estimasi/final.
- [ ] **Phase 4 — Tes**:
  - [ ] Unit test mapper (TikTok platform_discount, Shopee escrow → kanonik).
  - [ ] Test `updateOrderFinance` tidak mengubah status/stok.
  - [ ] Test idempotensi `SyncOrderFinanceJob` (run 2x → hasil sama).
  - [ ] Test sweep hanya menyentuh order `COMPLETED & !is_settled`.

---

## 8. Edge Cases & Guard

- **Order belum settle** → fee final null, `is_settled=false`. Jangan hitung net.
- **Refund/return sebagian** → settlement bisa berubah; sweep menarik ulang selama belum `is_settled` final, atau saat status return berubah (hook ke modul Sales Return yang sudah ada — **read-only**, tidak diubah).
- **Multi-package / split order** → jumlahkan fee lintas package per `order_sn`/`order_id`.
- **Mata uang** → simpan `fee_currency`; default IDR.
- **Pull ulang order detail** setelah finance settle **tidak boleh** menimpa kolom finance final — karena order-detail tidak punya kolom finance, mapper order **tidak** menulis kolom finance final (hanya estimasi voucher); `updateOrderFinance` jalur terpisah. Aman.
- **Rate limit channel** → sweep batch + backoff; jangan tarik finance untuk order yang sudah `is_settled`.

---

## 9. Definition of Done
- Order TikTok & Shopee yang sudah `COMPLETED` memiliki `commission_fee`, `service_fee`, voucher seller+platform, dan `settlement_amount` terisi dengan `is_settled = true`.
- Tidak ada perubahan pada perilaku stok/status/picklist (test regресi hijau).
- FE menampilkan rincian biaya + status estimasi/final.
- Semua angka dapat direkonsiliasi terhadap `sales_order_fee_lines` (jika Phase 3 diaktifkan).
