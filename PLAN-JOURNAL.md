# PLAN — Journal & Chart of Accounts (Tracker: 5 item domain Journal)

**PIC:** Darriel · **Modul:** `Modules/Finance` · **Sumber:** Jubelio `dist (2).yaml`

| # | Method | Endpoint | operationId | Fungsi |
|---|---|---|---|---|
| 1 | GET | `/accounts/lookup/all` | getAccountLookupAll | Daftar akun (COA) utk dropdown |
| 2 | GET | `/journal/` | getJournal | Semua jurnal (paginated) |
| 3 | GET | `/journal/{id}` | getJournalById | Detail jurnal + baris debit/kredit |
| 4 | GET | `/journal/manual-journal/` | getJournalManual | Jurnal manual saja |
| 5 | POST | `/journal/manual-journal/` | postManualJournal | Buat (journal_id=0) / ubah jurnal manual |

> Catatan spec: `saveManualJournalRequest.required` di Jubelio mencantumkan `picklist_id/picklist_no/is_completed` — **bug copy-paste di dokumen mereka**; properti sebenarnya `journal_id, notes, source_doc_no, transaction_date, accounts[]{account_id, debit, credit, description, journal_detail_id}`. Kita ikuti properti, bukan required yang salah.

---

## 1. Arsitektur & keputusan kunci

### 1a. Skema database (semua UUID, `HasUuid7`)

```
accounts                      journals                          journal_details
─ id (uuid)                   ─ id (uuid)                       ─ id (uuid)
─ account_code  (1-1000, uq)  ─ journal_no (GJ-0000001, uq)     ─ journal_id (FK cascade)
─ account_name  (Kas)         ─ transaction_date                ─ account_id (FK restrict)
─ account_type  (asset|       ─ journal_type (null | 'Manual    ─ debit  (decimal 18,4)
   liability|equity|             Jurnal')  ← kontrak Jubelio    ─ credit (decimal 18,4)
   revenue|expense)           ─ source_doc_type (nullable)      ─ description (nullable)
─ is_active                   ─ source_doc_id   (nullable)      
                              ─ source_doc_no   (nullable)      index: journal_id, account_id
index: account_code           ─ notes, total_debit, total_credit
                              ─ created_by (nullable FK users)
                              unique: (source_doc_type, source_doc_id)  ← idempoten
```

- `journal_type` mengikuti Jubelio persis: `null` = jurnal otomatis, `'Manual Jurnal'` = manual.
- Unique `(source_doc_type, source_doc_id)` = **idempoten**: satu dokumen sumber satu jurnal (retry/observer dobel tidak menggandakan).
- `account_name` di response = format Jubelio `"1-1000 - Kas"` (code + nama, dirakit di accessor).

### 1b. Seeder COA (selaras config cashbank yang sudah jalan)

| Code | Nama | Tipe |
|---|---|---|
| 1-1000 | Kas | asset |
| 1-1001 | Bank | asset |
| 1-1002 | Giro | asset |
| 1-1100 | Piutang Usaha | asset |
| 1-1200 | Persediaan Barang | asset |
| 2-2000 | Hutang Usaha | liability |
| 3-3000 | Modal | equity |
| 4-4000 | Pendapatan Penjualan | revenue |
| 4-4100 | Diskon Penjualan | revenue |
| 5-5000 | Harga Pokok Penjualan | expense |
| 6-6000 | Beban Operasional | expense |
| 6-6100 | Beban Penyesuaian Persediaan | expense |

Idempoten (`firstOrCreate` by code). Kode 1-1000/1-1001/1-1100/2-2000 **identik** dengan `config/finance.php` cashbank → integrasi mulus.

### 1c. Auto-journal: Observer (pola terbukti modul Webhook — non-invasif ke kode Rasyid)

Observer `created` berjalan **di dalam transaksi DB yang sama** dengan dokumen sumber → jurnal & dokumen atomik (commit/rollback bersama). Insert ringan (2 baris) — tidak memperpanjang lock berarti. Dibungkus try/catch + log: **kegagalan jurnal tidak boleh menggagalkan transaksi bisnis** (fail-open, dicatat untuk rekonsiliasi).

| Dokumen sumber (modul Rasyid) | Jurnal otomatis (Dr / Cr) |
|---|---|
| `SalesInvoice` created | Dr **Piutang Usaha** — Cr **Pendapatan Penjualan** (total_amount) |
| `SalesPayment` created | Dr **Kas/Bank** (map payment_method) — Cr **Piutang Usaha** (amount) |
| `PurchaseBill` created | Dr **Persediaan Barang** — Cr **Hutang Usaha** (total_amount) |
| `PurchasePayment` created | Dr **Hutang Usaha** — Cr **Kas/Bank** (amount) |

- `source_doc_no` = nomor dokumen (INV-/REC-/BILL-/PAY-) — persis contoh Jubelio (`source_doc_no: ADJS-…`).
- Skip senyap bila amount ≤ 0 atau COA belum di-seed (log warning) — **tidak pernah 500 di alur bisnis**.
- **Di luar scope (jujur):** jurnal HPP/COGS saat penjualan (butuh costing per item — belum ada di sistem), stock adjustment bernilai uang (butuh harga pokok), void/reversal. Dicatat sebagai fase lanjutan.

### 1d. Penomoran GJ-XXXXXXX concurrency-safe

`max(journal_no) + 1` di dalam transaksi **dengan `lockForUpdate()` pada baris jurnal terakhir** + retry-once pada unique violation → dua transaksi paralel tidak menghasilkan nomor kembar.

### 1e. Upgrade cashbank (menunaikan PLAN-CASHBANK §7 — non-breaking)

`CashbankService::mapDetail` → cari jurnal nyata by `source_doc_type+source_doc_id`; **bila ada** pakai baris jurnal nyata (`journal_detail_id` terisi), **bila tidak** (data lama pra-Journal) fallback ke sintesis yang ada. Kontrak response tidak berubah → test cashbank lama tetap hijau.

---

## 2. Endpoint & kontrak (bentuk = Jubelio)

### `GET /v1/accounts/lookup/all`
Lookup dropdown — tanpa pagination (spec hanya `authorization`) → **Eloquent biasa** (pengecualian agents.md §3 untuk fixed list): semua akun aktif urut code. Response item: `{account_id (uuid), account_code, account_name ("1-1000 - Kas")}`.

### `GET /v1/journal/` dan `GET /v1/journal/manual-journal/`
Spatie QB (paginate 10 + appends): sort `transaction_date|journal_no|created_at`, filter `q` (cari di `journal_no|source_doc_no|notes`), `createdSince` (date, invalid→422). Manual = scope `journal_type='Manual Jurnal'`. Item list: `journal_id, journal_no, journal_type, transaction_date, source_doc_no, notes, total debit/credit (string 4 desimal)`.

### `GET /v1/journal/{id}`
`whereUuid` → non-UUID 404. Detail + `accounts[]`: `{journal_detail_id, account_id, account_name, debit, credit, description}`.

### `POST /v1/journal/manual-journal/`
Body (kontrak Jubelio): `journal_id` (`0`/null = buat; uuid = ubah), `notes`, `transaction_date`, `accounts[]`.

**Aturan bisnis (FormRequest + Service):**
1. `accounts` min **2 baris**; tiap baris `account_id` wajib `bail|uuid|exists:accounts,id` (non-uuid → 422 bukan 500).
2. `debit`/`credit` ≥ 0; per baris **tepat satu sisi terisi** (debit>0 xor credit>0).
3. **Seimbang**: Σdebit = Σcredit (presisi 4 desimal) → tidak → 422 dengan pesan jelas.
4. Edit: target harus ada (404) **dan** `journal_type='Manual Jurnal'` — **jurnal otomatis tidak boleh diubah** (422). Edit = replace lines dalam transaksi (hapus detail lama, tulis baru, recalc total).
5. Create: nomor GJ baru, `journal_type='Manual Jurnal'`, `created_by` user login.
6. Seluruh tulis dalam `DB::transaction`.

---

## 3. Struktur file

```
Modules/Finance/
├── database/migrations/  create_accounts_table, create_journals_table, create_journal_details_table
├── database/seeders/     ChartOfAccountsSeeder (dipanggil FinanceDatabaseSeeder)
├── app/Models/           Account, Journal, JournalDetail
├── app/Repositories/     AccountRepository, JournalRepository (QB list, find, nextJournalNo, createWithLines, replaceLines)
├── app/Services/         JournalService (validasi bisnis manual journal, orkestrasi)
│                         AutoJournalService (buildFor{Invoice,SalesPayment,Bill,PurchasePayment} — dipakai observer)
├── app/Observers/        SalesInvoiceJournalObserver, SalesPaymentJournalObserver,
│                         PurchaseBillJournalObserver, PurchasePaymentJournalObserver
├── app/Providers/EventServiceProvider  ← Model::observe x4 (pola modul Webhook)
├── app/Http/Requests/    SaveManualJournalRequest, JournalIndexRequest
├── app/Http/Controllers/ AccountLookupController, JournalController
├── routes/api.php        +5 route (nama unik, whereUuid)
└── tests/Feature/        JournalApiTest, AutoJournalObserverTest, CashbankJournalUpgradeTest
```

CashbankService diubah minimal (cek jurnal nyata → fallback sintesis).

---

## 4. Matriks use case (semuanya diuji)

| # | Use case | Hasil |
|---|---|---|
| U1 | Lookup akun setelah seed | 200, format `1-1000 - Kas`, urut code |
| U2 | Buat SalesInvoice → jurnal otomatis | Dr Piutang Cr Pendapatan, no GJ-, source_doc_no=INV-, seimbang |
| U3 | Buat SalesPayment (transfer) | Dr Bank Cr Piutang |
| U4 | Buat PurchaseBill | Dr Persediaan Cr Hutang |
| U5 | Buat PurchasePayment (cash) | Dr Hutang Cr Kas |
| U6 | Dokumen sumber sama dua kali (idempoten) | 1 jurnal (unique source) |
| U7 | COA belum di-seed saat transaksi | transaksi bisnis TETAP sukses, jurnal di-skip + log |
| U8 | `GET /journal/` paginate 10 + `q` cari journal_no/source_doc_no | benar |
| U9 | `createdSince` invalid | 422 |
| U10 | `GET /journal/{id}` non-UUID / asing | 404 / 404 |
| U11 | POST manual seimbang (journal_id=0) | 201/200, GJ baru, type Manual Jurnal |
| U12 | POST tidak seimbang | 422 pesan "tidak seimbang" |
| U13 | POST 1 baris saja / baris dua sisi terisi / dua sisi nol | 422 |
| U14 | POST account_id non-uuid / tidak ada | 422 (bail) |
| U15 | Edit jurnal manual (journal_id=uuid) | baris ter-replace, total recalc |
| U16 | Edit jurnal OTOMATIS via endpoint manual | 422 ditolak |
| U17 | Edit journal_id asing | 404 |
| U18 | `manual-journal/` hanya berisi manual | jurnal otomatis tak muncul |
| U19 | Cashbank detail pasca-Journal | `accounts[]` = jurnal nyata, `journal_detail_id` terisi |
| U20 | Cashbank data lama tanpa jurnal | fallback sintesis (test cashbank lama tetap hijau) |
| U21 | Penomoran paralel | tidak duplikat (retry on unique) |
| U22 | Tanpa auth | 401 semua endpoint |

## 5. Integrasi & no-500

| Aspek | Jaminan |
|---|---|
| Modul Rasyid | Observer-only (pola Webhook yang sudah diterima) — nol perubahan kode Sales/Purchase; fail-open: error jurnal tak menggagalkan transaksi |
| Stock locking | Tidak menyentuh inventories; insert jurnal ringan dalam txn yang sama |
| Webhook module | Tidak bentrok — observer berbeda, event model sama boleh punya banyak observer |
| Cashbank | Upgrade non-breaking + fallback |
| Queue/Horizon | Nol job baru |
| route:cache | Nama route unik `finance.journal.*`, `finance.accounts.lookup` |
| 500-proof | whereUuid, bail+uuid+exists, balanced→422, FormRequest semua input, COA kosong→skip |

## 6. Urutan eksekusi
1. Migrations + Models + COA seeder → migrate
2. Repos + JournalService (manual journal) + endpoints + routes
3. AutoJournalService + 4 observer + EventServiceProvider
4. Upgrade CashbankService (real journal → fallback)
5. 3 file test (≈22 kasus) → hijau + suite Finance/Sales/Purchase/Channel regresi + route:cache
6. Tracker 5 item Journal → done (DB+seeder+generator) → commit → merge main

## 7. Definition of Done
- [ ] 5 endpoint sesuai kontrak Jubelio (termasuk perilaku journal_id=0/uuid)
- [ ] 4 jurnal otomatis seimbang + idempoten + fail-open
- [ ] Cashbank memakai jurnal nyata dengan fallback (test lama tetap hijau)
- [ ] Semua use case U1–U22 hijau, route:cache OK, tracker updated
