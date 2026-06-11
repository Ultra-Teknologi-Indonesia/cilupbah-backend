# PLAN — Cash & Bank (Tracker ID 45–48)

**Domain:** Cash & Bank · **PIC:** Darriel · **Modul:** `Modules/Finance` (saat ini masih kerangka)
**Sumber:** Jubelio `dist (2).yaml` (`getPayments`, `getPaymentsById`, `getCashbankReceives`, `getCashbankReceiveById`)

| ID | Method | Endpoint | Fungsi |
|---|---|---|---|
| 45 | GET | `/cashbank/payments/` | Daftar pembayaran kas/bank (uang keluar) |
| 46 | GET | `/cashbank/payments/{id}` | Detail satu pembayaran |
| 47 | GET | `/cashbank/receives` | Daftar penerimaan kas/bank (uang masuk) |
| 48 | GET | `/cashbank/receives/{id}` | Detail satu penerimaan |

---

## 1. Analisis & keputusan arsitektur

**Realita data cilupbah saat ini:**
- **Uang masuk** sudah ada → `Modules\Sales\Models\SalesPayment` (pelanggan bayar invoice): `payment_number, sales_invoice_id, amount, payment_date, payment_method, reference_no, notes`. Relasi `invoice()` → `SalesInvoice` (punya `customer_name`).
- **Uang keluar** sudah ada → `Modules\Purchase\Models\PurchasePayment` (bayar supplier): field setara, relasi `bill()` → `PurchaseBill` (→ supplier).
- **BELUM ada** Chart of Accounts / Journal/GL (domain **Journal** masih `todo`, 5 item). Jadi `account_id/account_name` ("1-1001 - Bank") dan rincian jurnal (`accounts[]` debit/kredit) di Jubelio belum punya backing nyata.

**Keputusan:** Cash & Bank diimplementasikan sebagai **VIEW read-only yang mengagregasi pembayaran yang sudah ada** — bukan tabel transaksi baru. Ini:
- **Non-invasif** terhadap modul Rasyid (Sales/Purchase `done`) — hanya membaca, persis pola Report/Observer. Tidak mengubah kode mereka.
- `receives` = `SalesPayment`, `payments` = `PurchasePayment`.
- **Tidak** membangun GL prematur. Saat domain **Journal/Chart of Accounts** dibangun nanti, cashbank tinggal diarahkan ke akun & jurnal sungguhan (lihat §7).

> Catatan keterbatasan jujur: entri kas/bank **manual** (tidak terkait sales/purchase, mis. setoran modal, biaya operasional) belum tercakup — itu butuh GL/Journal. Didokumentasikan sebagai scope lanjutan, bukan bagian 4 endpoint ini.

## 2. Mapping akun kas/bank (tanpa GL)

`SalesPayment/PurchasePayment` punya `payment_method` (cash/transfer/dll) tapi **tidak** menyimpan akun bank spesifik. Maka akun kas/bank dipetakan dari `payment_method` lewat **config** (bukan tabel baru — hindari skema prematur sebelum Chart of Accounts resmi):

`config/finance.php`:
```php
'cashbank_accounts' => [
    'cash'     => ['id' => '1-1000', 'name' => '1-1000 - Kas'],
    'transfer' => ['id' => '1-1001', 'name' => '1-1001 - Bank'],
    'bank'     => ['id' => '1-1001', 'name' => '1-1001 - Bank'],
    // default fallback → Kas
],
```

## 3. Bentuk response (selaras agents.md + setara Jubelio)

Pakai `ApiResponse::successPaginatedResponse` (standar cilupbah) → `{status,message,data,meta{total,...}}`. `meta.total` = padanan `totalCount` Jubelio.

**Item list** (mapping ke schema Jubelio `getCashbankReceivesResponse`):
```
account_id, account_name, amount, contact_id, contact_name,
doc_type ("Penerimaan"/"Pembayaran"), payment_id, payment_no, transaction_date
```
**Detail** (`getCashbankPaymentByIdResponse`): item di atas + `cashbank_account_id/name`, `note`, `payment_type`, dan `accounts[]` = **rincian jurnal dua-baris yang disintesis** (karena belum ada GL):
- Penerimaan: Dr Kas/Bank (amount) · Cr Piutang Usaha (amount)
- Pembayaran: Dr Hutang Usaha (amount) · Cr Kas/Bank (amount)

Disintesis deterministik & benar secara akuntansi; ditandai `journal_detail_id: null` (belum ada jurnal nyata).

## 4. Struktur file (Modul Finance)

```
Modules/Finance/
├── app/Http/Controllers/CashbankController.php      # thin: payments(), paymentShow(), receives(), receiveShow()
├── app/Services/CashbankService.php                 # logika mapping + sintesa jurnal
├── app/Repositories/CashbankRepository.php          # Spatie QB atas SalesPayment & PurchasePayment
├── app/Http/Resources/CashbankItemResource.php      # bentuk item list
├── app/Http/Resources/CashbankDetailResource.php    # bentuk detail + accounts[]
├── routes/api.php                                   # 4 route
config/finance.php                                   # map cashbank_accounts (EDIT/baru)
```

### Repository (Spatie QB — agents.md §3,4,5)
```php
public function paginatePayments()  // PurchasePayment
{
    return QueryBuilder::for(PurchasePayment::class)
        ->with('bill.supplier')                // resolusi contact
        ->allowedFilters([
            AllowedFilter::scope('transaction_date_from', 'paymentDateFrom'),
            AllowedFilter::scope('transaction_date_to', 'paymentDateTo'),
        ])
        ->allowedSorts('payment_date', 'amount', 'payment_number')
        ->defaultSort('-payment_date')
        ->paginate(request('per_page', 10))
        ->appends(request()->query());
}
public function findPayment(string $id): ?PurchasePayment   // Eloquent find (single record)
// idem paginateReceives()/findReceive() untuk SalesPayment + 'invoice'
```
*Filter tanggal via query scope `paymentDateFrom/To` di masing-masing model (atau `AllowedFilter::callback`).* Param Jubelio `transactionDateFrom/To` → `filter[transaction_date_from]=`.

### Service
- `listPayments()/listReceives()` → repo paginate, map koleksi ke `CashbankItemResource`.
- `paymentDetail($id)/receiveDetail($id)` → repo find (guard uuid → null/404), bangun `accounts[]` tersintesa, `CashbankDetailResource`.
- `resolveAccount($paymentMethod)` → baca `config('finance.cashbank_accounts')`.
- `resolveContact()` → receives: `invoice->customer_name`; payments: `bill->supplier->name`.

### Controller (thin)
`payments(Request)`, `paymentShow(string $id)`, `receives(Request)`, `receiveShow(string $id)` → panggil service → `successPaginatedResponse`/`successResponse`. Detail tidak ketemu → `errorResponse(404)`.

### Routes (`Modules/Finance/routes/api.php`)
```php
Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('cashbank/payments', [CashbankController::class, 'payments'])->name('cashbank.payments.index');
    Route::get('cashbank/payments/{id}', [CashbankController::class, 'paymentShow'])->whereUuid('id')->name('cashbank.payments.show');
    Route::get('cashbank/receives', [CashbankController::class, 'receives'])->name('cashbank.receives.index');
    Route::get('cashbank/receives/{id}', [CashbankController::class, 'receiveShow'])->whereUuid('id')->name('cashbank.receives.show');
});
```
`{id}` di-`whereUuid` → id non-UUID = **404** (cegah cast uuid Postgres 500). Nama route unik → `route:cache` aman.

## 5. No-500 & integrasi
| Aspek | Jaminan |
|---|---|
| id non-UUID di detail | `whereUuid` → 404 |
| id valid tapi tak ada | service return null → controller 404 |
| filter tanggal invalid | divalidasi (FormRequest/`whereDate` aman) → 422, bukan 500 |
| Bentrok modul Rasyid | **read-only**; tidak ada migration/observer yang menyentuh Sales/Purchase |
| Kontribusi RAM/worker | nol job/queue — murni HTTP read |

## 6. Testing (`Modules/Finance/tests/Feature/CashbankApiTest.php`)
1. `receives_lists_sales_payments` — buat SalesPayment+invoice → `GET /cashbank/receives` 200, `doc_type=Penerimaan`, `amount/contact_name` benar, paginate 10.
2. `payments_lists_purchase_payments` — PurchasePayment+bill+supplier → `GET /cashbank/payments` 200, `doc_type=Pembayaran`.
3. `receive_detail_has_synthesized_journal` — `GET /cashbank/receives/{id}` 200, `accounts[]` 2 baris (Dr Kas/Bank, Cr Piutang) seimbang.
4. `payment_detail_account_mapping` — `payment_method=transfer` → `cashbank_account_name="1-1001 - Bank"`.
5. `date_filter_narrows_results` — `filter[transaction_date_from]/_to` mempersempit.
6. `non_uuid_id_returns_404` & `unknown_id_returns_404`.
7. `requires_auth` → 401.

Target: hijau + `route:cache` sukses + tidak ada regresi suite Sales/Purchase/Finance.

## 7. Integrasi masa depan (saat domain Journal/Chart of Accounts dibangun)
- Ganti `config/finance.php` map → tabel **chart_of_accounts** nyata; `account_id` jadi FK.
- `accounts[]` detail diambil dari **jurnal sungguhan** (journal_details) alih-alih disintesis; `journal_detail_id` terisi.
- Tambahkan **entri kas/bank manual** (di luar sales/purchase) → cashbank jadi union 3 sumber.
Desain §1–§4 sengaja dibuat agar transisi ini non-breaking (Resource & route tetap).

## 8. Definition of Done
- [ ] 4 endpoint jalan sesuai fungsi (uang masuk/keluar + detail)
- [ ] Mapping akun, contact, dan `accounts[]` tersintesa benar
- [ ] Spatie QB (per_page 10, appends, filter tanggal, sort)
- [ ] No-500 (uuid guard 404, filter invalid 422), read-only non-invasif
- [ ] Test hijau + `route:cache` sukses
- [ ] Tracker ID 45–48 → `done`
- [ ] Commit + push + merge main
