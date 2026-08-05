# Requirements Document

## Introduction

Fitur **Sales Full Cycle** melengkapi siklus penjualan pada WMS Superapp (`cilupbah-be`) dengan menambahkan kemampuan keuangan yang belum ada pada modul `Sales` (saat ini baru `SalesReturn`) dan modul `Order` (saat ini baru CRUD + transisi status). Fitur ini menutup celah monetization flow: dari Order yang sudah ada, sistem dapat menerbitkan **Sales Invoice**, mencatat **Sales Payment** (termasuk pembayaran sebagian dan banyak metode), melakukan **Sales Settlement** (rekonsiliasi pembayaran marketplace dan penyelesaian sales return), serta menambah **Order Enhancements** (set-as-paid, mark-complete, cancel, dan beberapa view operasional).

Fokus utama dokumen ini adalah **business rules keuangan**: konsistensi nilai invoice terhadap order, dukungan partial payment, penetapan status lunas otomatis, pencegahan overpayment, dan keterkaitan rantai data Invoice ↔ Payment ↔ Settlement ↔ `Order.is_paid`.

Acuan alur bisnis mengikuti **flow & business rules sistem lama** (dist (2).yaml & dist (3).yaml), namun URL/endpoint menggunakan standar RESTful internal (`/api/v1/...`), bukan path sistem lama. Implementasi wajib mengikuti `AGENTS.md`: Controller tipis, Service untuk logika bisnis, Repository untuk semua query, trait `App\Traits\ApiResponse`, Eloquent Resources, `spatie/laravel-query-builder` untuk listing, dan pagination default 10 per halaman.

Struktur `SalesReturn` / `SalesReturnItem` yang sudah ada **TIDAK BOLEH** diubah; fitur baru hanya menambah keterkaitan ke return melalui Settlement.

## Glossary

- **Sales_Invoice_Service**: Komponen logika bisnis yang menangani pembuatan, pembaruan, penghapusan, dan penerbitan tagihan penjualan (Sales Invoice).
- **Sales_Payment_Service**: Komponen logika bisnis yang menangani pencatatan pembayaran terhadap Sales Invoice.
- **Sales_Settlement_Service**: Komponen logika bisnis yang menangani rekonsiliasi/penyelesaian pembayaran marketplace dan penyelesaian sales return.
- **Order_Service**: Komponen logika bisnis modul Order yang sudah ada, diperluas dengan operasi keuangan dan view operasional.
- **Sales_Invoice**: Dokumen tagihan penjualan yang diterbitkan dari sebuah Order. Memiliki nomor dokumen otomatis, status pembayaran, dan tanggal jatuh tempo.
- **Sales_Payment**: Catatan satu transaksi pembayaran yang diterapkan terhadap satu Sales Invoice.
- **Sales_Settlement**: Catatan rekonsiliasi yang menyelesaikan satu atau lebih Sales Invoice atau satu Sales Return.
- **Order**: Pesanan penjualan yang sudah ada (tabel `orders`), memiliki field `grand_total`, `is_paid`, `is_canceled`, `paid_time`, `status`.
- **Sales_Return**: Retur penjualan yang sudah ada (tabel `sales_returns`), tidak diubah strukturnya.
- **Document_Number**: Nomor dokumen unik dengan format `PREFIX-YYYYMMDD-0001`, auto-increment per hari per jenis dokumen.
- **Invoice_Amount**: Nilai total tagihan sebuah Sales Invoice, sama dengan `grand_total` Order asal pada saat penerbitan.
- **Paid_Amount**: Akumulasi nilai seluruh Sales Payment berstatus tercatat pada satu Sales Invoice.
- **Outstanding_Amount**: Selisih `Invoice_Amount` dikurangi `Paid_Amount` untuk satu Sales Invoice.
- **Invoice_Payment_Status**: Status pembayaran Sales Invoice; salah satu dari `UNPAID`, `PARTIAL`, `PAID`.
- **Invoice_Status**: Status siklus hidup Sales Invoice; salah satu dari `DRAFT`, `ISSUED`, `PAID`, `CANCELLED`.
- **Due_Date**: Tanggal jatuh tempo pembayaran Sales Invoice.
- **Overdue**: Kondisi Sales Invoice ketika `Due_Date` sudah terlewat dan `Invoice_Payment_Status` bukan `PAID`.
- **Settlement_Status**: Status Sales Settlement; salah satu dari `PENDING`, `SETTLED`, `CANCELLED`.
- **Payment_Method**: Metode pembayaran, misalnya `cash`, `transfer`, `marketplace`, `va`, `credit_card`.

## Requirements

### Requirement 1: Pembuatan Sales Invoice dari Order

**User Story:** Sebagai staf penjualan, saya ingin menerbitkan Sales Invoice dari sebuah Order, sehingga tagihan penjualan tercatat dengan nilai yang konsisten terhadap pesanan.

#### Acceptance Criteria

1. WHEN pengguna meminta pembuatan Sales Invoice dengan menyertakan `order_id` yang valid, THE Sales_Invoice_Service SHALL membuat Sales_Invoice dengan `Invoice_Amount` sama dengan `grand_total` Order asal.
2. WHEN Sales_Invoice dibuat, THE Sales_Invoice_Service SHALL menghasilkan Document_Number dengan format `INV-YYYYMMDD-0001` yang auto-increment per hari.
3. WHEN Sales_Invoice dibuat tanpa `due_date` yang diberikan pengguna, THE Sales_Invoice_Service SHALL menetapkan `Due_Date` menggunakan aturan default sistem berbasis `transaction_date` Order.
4. WHEN Sales_Invoice dibuat, THE Sales_Invoice_Service SHALL menetapkan `Invoice_Payment_Status` ke `UNPAID` dan `Paid_Amount` ke 0.
5. IF `order_id` yang diberikan tidak ditemukan, THEN THE Sales_Invoice_Service SHALL menolak pembuatan dan mengembalikan pesan kesalahan deskriptif.
6. IF Order yang dirujuk memiliki `is_canceled` bernilai true, THEN THE Sales_Invoice_Service SHALL menolak pembuatan dan mengembalikan pesan kesalahan deskriptif.
7. IF sudah terdapat Sales_Invoice aktif (Invoice_Status bukan `CANCELLED`) untuk `order_id` yang sama, THEN THE Sales_Invoice_Service SHALL menolak pembuatan dan mengembalikan pesan kesalahan deskriptif.

### Requirement 2: Pengelolaan Sales Invoice (CRUD)

**User Story:** Sebagai staf penjualan, saya ingin mengelola Sales Invoice, sehingga data tagihan dapat ditinjau, diperbarui, dan dibatalkan sesuai kebutuhan.

#### Acceptance Criteria

1. WHEN pengguna meminta daftar Sales_Invoice, THE Sales_Invoice_Service SHALL mengembalikan data ter-paginate dengan default 10 item per halaman dan menerima parameter `per_page`.
2. THE Sales_Invoice_Service SHALL menyediakan pencarian teks bebas melalui parameter `?search=` pada field `Document_Number` dan `customer_name`.
3. THE Sales_Invoice_Service SHALL menyediakan filter daftar berdasarkan `Invoice_Payment_Status`, `Invoice_Status`, dan `customer_name`.
4. THE Sales_Invoice_Service SHALL menyediakan pengurutan daftar berdasarkan `created_at`, `Due_Date`, dan `Invoice_Amount`.
5. WHEN pengguna meminta detail satu Sales_Invoice dengan identifier valid, THE Sales_Invoice_Service SHALL mengembalikan data invoice beserta `Paid_Amount` dan `Outstanding_Amount`.
6. WHEN pengguna memperbarui Sales_Invoice yang berstatus `DRAFT`, THE Sales_Invoice_Service SHALL menyimpan perubahan pada field yang diizinkan.
7. IF pengguna memperbarui Sales_Invoice yang `Invoice_Payment_Status`-nya bukan `UNPAID`, THEN THE Sales_Invoice_Service SHALL menolak perubahan `Invoice_Amount` dan `Due_Date` serta mengembalikan pesan kesalahan deskriptif.
8. WHILE Sales_Invoice memiliki `Invoice_Payment_Status` bukan `UNPAID`, THE Sales_Invoice_Service SHALL tetap mengizinkan pembaruan field non-finansial seperti catatan dan deskripsi.
9. WHEN pengguna membatalkan Sales_Invoice yang `Paid_Amount`-nya bernilai 0, THE Sales_Invoice_Service SHALL menetapkan `Invoice_Status` ke `CANCELLED`.
10. IF pengguna membatalkan Sales_Invoice yang `Paid_Amount`-nya lebih besar dari 0, THEN THE Sales_Invoice_Service SHALL menolak pembatalan dan mengembalikan pesan kesalahan deskriptif.
### Requirement 3: View Overdue, Unpaid, dan Summary Invoice

**User Story:** Sebagai manajer keuangan, saya ingin melihat invoice yang belum dibayar, jatuh tempo, dan ringkasan totalnya, sehingga saya dapat memantau piutang.

#### Acceptance Criteria

1. WHEN pengguna meminta daftar invoice unpaid, THE Sales_Invoice_Service SHALL mengembalikan Sales_Invoice yang `Invoice_Payment_Status`-nya `UNPAID` atau `PARTIAL` secara ter-paginate.
2. WHEN pengguna meminta daftar invoice overdue, THE Sales_Invoice_Service SHALL mengembalikan Sales_Invoice yang `Due_Date`-nya lebih awal dari tanggal saat ini dan `Invoice_Payment_Status`-nya bukan `PAID`.
3. WHEN pengguna meminta ringkasan invoice, THE Sales_Invoice_Service SHALL mengembalikan total `Invoice_Amount`, total `Paid_Amount`, dan total `Outstanding_Amount` dari seluruh Sales_Invoice yang Invoice_Status-nya bukan `CANCELLED`.

### Requirement 4: Pencatatan Sales Payment

**User Story:** Sebagai staf keuangan, saya ingin mencatat pembayaran terhadap sebuah invoice, sehingga status pelunasan tagihan selalu akurat.

#### Acceptance Criteria

1. WHEN pengguna mencatat Sales_Payment terhadap Sales_Invoice valid dengan `amount` lebih besar dari 0, THE Sales_Payment_Service SHALL menyimpan catatan pembayaran beserta `Payment_Method` dan tanggal pembayaran.
2. WHEN Sales_Payment dicatat, THE Sales_Payment_Service SHALL menghasilkan Document_Number dengan format `PAY-YYYYMMDD-0001` yang auto-increment per hari.
3. WHEN Sales_Payment berhasil dicatat, THE Sales_Payment_Service SHALL memperbarui `Paid_Amount` Sales_Invoice menjadi akumulasi seluruh pembayaran tercatat.
4. WHILE `Paid_Amount` lebih besar dari 0 dan lebih kecil dari `Invoice_Amount`, THE Sales_Payment_Service SHALL menetapkan `Invoice_Payment_Status` ke `PARTIAL`.
5. WHEN `Paid_Amount` mencapai nilai yang sama dengan `Invoice_Amount`, THE Sales_Payment_Service SHALL menetapkan `Invoice_Payment_Status` ke `PAID` dan `Invoice_Status` ke `PAID`.
6. WHEN `Invoice_Payment_Status` berubah menjadi `PAID`, THE Sales_Payment_Service SHALL menetapkan `Order.is_paid` ke true dan mengisi `Order.paid_time` dengan waktu pelunasan.
7. IF `amount` Sales_Payment menyebabkan `Paid_Amount` melebihi `Invoice_Amount`, THEN THE Sales_Payment_Service SHALL menolak pencatatan dan mengembalikan pesan kesalahan deskriptif.
8. IF `amount` Sales_Payment bernilai kurang dari atau sama dengan 0, THEN THE Sales_Payment_Service SHALL menolak pencatatan dan mengembalikan pesan kesalahan deskriptif.
9. IF Sales_Payment dicatat terhadap Sales_Invoice yang `Invoice_Status`-nya `CANCELLED`, THEN THE Sales_Payment_Service SHALL menolak pencatatan dan mengembalikan pesan kesalahan deskriptif.
10. WHERE `Payment_Method` diberikan, THE Sales_Payment_Service SHALL memvalidasi bahwa metode termasuk dalam daftar metode pembayaran yang didukung.

### Requirement 5: Daftar dan Detail Sales Payment

**User Story:** Sebagai staf keuangan, saya ingin meninjau pembayaran yang telah dicatat, sehingga saya dapat menelusuri riwayat penerimaan kas per invoice.

#### Acceptance Criteria

1. WHEN pengguna meminta daftar Sales_Payment, THE Sales_Payment_Service SHALL mengembalikan data ter-paginate dengan default 10 item per halaman dan menerima parameter `per_page`.
2. THE Sales_Payment_Service SHALL menyediakan filter daftar berdasarkan `Payment_Method` dan identifier Sales_Invoice.
3. THE Sales_Payment_Service SHALL menyediakan pengurutan daftar berdasarkan `created_at` dan `amount`.
4. WHEN pengguna meminta daftar pembayaran untuk satu Sales_Invoice, THE Sales_Payment_Service SHALL mengembalikan seluruh Sales_Payment yang terkait dengan invoice tersebut.

### Requirement 6: Sales Settlement (Rekonsiliasi Pembayaran)

**User Story:** Sebagai staf keuangan, saya ingin merekonsiliasi pembayaran (termasuk dari marketplace), sehingga pelunasan invoice tercermin dengan benar.

#### Acceptance Criteria

1. WHEN pengguna membuat Sales_Settlement terhadap satu atau lebih Sales_Invoice valid, THE Sales_Settlement_Service SHALL membuat catatan settlement dengan Document_Number berformat `STL-YYYYMMDD-0001` dan `Settlement_Status` `PENDING`.
2. WHEN Sales_Settlement diselesaikan (settle), THE Sales_Settlement_Service SHALL mencatat Sales_Payment untuk setiap Sales_Invoice terkait sesuai nilai settlement dan menetapkan `Settlement_Status` ke `SETTLED`.
3. WHEN Sales_Settlement diselesaikan dan menyebabkan suatu Sales_Invoice lunas, THE Sales_Settlement_Service SHALL memicu pembaruan `Invoice_Payment_Status` dan `Order.is_paid` melalui aturan yang sama dengan Requirement 4.
4. IF nilai settlement untuk suatu Sales_Invoice menyebabkan `Paid_Amount` melebihi `Invoice_Amount`, THEN THE Sales_Settlement_Service SHALL menolak penyelesaian dan mengembalikan pesan kesalahan deskriptif.
5. IF Sales_Settlement diselesaikan dalam keadaan `Settlement_Status` sudah `SETTLED`, THEN THE Sales_Settlement_Service SHALL menolak operasi dan mengembalikan pesan kesalahan deskriptif.
6. WHEN pengguna meminta daftar Sales_Settlement, THE Sales_Settlement_Service SHALL mengembalikan data ter-paginate dengan default 10 item per halaman dan menerima parameter `per_page`.

### Requirement 7: Return Settlement (Penyelesaian Sales Return)

**User Story:** Sebagai staf keuangan, saya ingin menyelesaikan penyelesaian keuangan dari sales return yang sudah ada, sehingga retur tercatat sebagai kewajiban yang telah diselesaikan.

#### Acceptance Criteria

1. WHEN pengguna membuat Return_Settlement dengan merujuk Sales_Return yang `status`-nya `COMPLETED`, THE Sales_Settlement_Service SHALL membuat catatan settlement bertipe return dengan Document_Number berformat `RST-YYYYMMDD-0001` dan `Settlement_Status` `PENDING`.
2. IF Sales_Return yang dirujuk `status`-nya bukan `COMPLETED`, THEN THE Sales_Settlement_Service SHALL menolak pembuatan Return_Settlement dan mengembalikan pesan kesalahan deskriptif.
3. WHEN Return_Settlement diselesaikan, THE Sales_Settlement_Service SHALL menetapkan `Settlement_Status` ke `SETTLED` dan mencatat waktu penyelesaian.
4. IF terdapat Return_Settlement aktif (Settlement_Status bukan `CANCELLED`) untuk Sales_Return yang sama, THEN THE Sales_Settlement_Service SHALL menolak pembuatan Return_Settlement baru dan mengembalikan pesan kesalahan deskriptif.
5. THE Sales_Settlement_Service SHALL memproses Return_Settlement tanpa mengubah struktur tabel `sales_returns` dan `sales_return_items`.

### Requirement 8: Order Enhancement — Set As Paid

**User Story:** Sebagai staf penjualan, saya ingin menandai sebuah Order sebagai sudah dibayar secara manual, sehingga Order yang dibayar di luar alur invoice tetap tercatat lunas.

#### Acceptance Criteria

1. WHEN pengguna menjalankan operasi set-as-paid pada Order yang `is_canceled`-nya false, THE Order_Service SHALL menetapkan `Order.is_paid` ke true dan mengisi `Order.paid_time` dengan waktu saat ini.
2. WHERE `payment_method` diberikan pada operasi set-as-paid, THE Order_Service SHALL menyimpan `payment_method` pada Order.
3. IF Order yang dirujuk sudah memiliki `is_paid` bernilai true, THEN THE Order_Service SHALL mengembalikan kondisi Order saat ini tanpa mengubah `paid_time`.
4. IF Order yang dirujuk `is_canceled`-nya true, THEN THE Order_Service SHALL menolak operasi set-as-paid dan mengembalikan pesan kesalahan deskriptif.

### Requirement 9: Order Enhancement — Mark Complete dan Cancel

**User Story:** Sebagai staf operasional, saya ingin menandai Order selesai atau membatalkannya, sehingga status Order mencerminkan kondisi akhir transaksi.

#### Acceptance Criteria

1. WHEN pengguna menjalankan operasi mark-complete pada Order yang `status`-nya `shipped`, THE Order_Service SHALL menetapkan `status` Order ke `completed`.
2. IF pengguna menjalankan mark-complete pada Order yang `status`-nya bukan `shipped`, THEN THE Order_Service SHALL menolak operasi dan mengembalikan pesan kesalahan deskriptif.
3. WHEN pengguna menjalankan operasi cancel pada Order yang `status`-nya termasuk transisi yang diizinkan ke `cancelled`, THE Order_Service SHALL menetapkan `status` ke `cancelled`, `is_canceled` ke true, dan menyimpan `cancel_reason`.
4. IF pengguna menjalankan cancel pada Order yang `status`-nya `shipped` atau `completed`, THEN THE Order_Service SHALL menolak operasi dan mengembalikan pesan kesalahan deskriptif.

### Requirement 10: Order Enhancement — View Operasional

**User Story:** Sebagai staf operasional, saya ingin melihat Order berdasarkan kondisi tertentu, sehingga saya dapat memproses pekerjaan sesuai prioritas.

#### Acceptance Criteria

1. WHEN pengguna meminta daftar Order failed/cancelled, THE Order_Service SHALL mengembalikan Order yang `is_canceled`-nya true secara ter-paginate dengan default 10 item per halaman.
2. WHEN pengguna meminta daftar returned, THE Order_Service SHALL mengembalikan Order yang memiliki Sales_Return terkait secara ter-paginate.
3. WHEN pengguna meminta daftar unfulfilled, THE Order_Service SHALL mengembalikan Order yang `status`-nya termasuk `pending` atau `reserved` dan `is_canceled`-nya false secara ter-paginate.
4. THE Order_Service SHALL menerapkan pencarian `?search=`, filter, dan pengurutan pada setiap view operasional menggunakan `spatie/laravel-query-builder`.

### Requirement 11: Konsistensi dan Integritas Data Keuangan

**User Story:** Sebagai pemilik sistem, saya ingin data invoice, payment, settlement, dan order saling konsisten, sehingga laporan keuangan dapat dipercaya.

#### Acceptance Criteria

1. THE Sales_Payment_Service SHALL menjaga invariant bahwa `Paid_Amount` setiap Sales_Invoice selalu sama dengan jumlah seluruh Sales_Payment tercatat pada invoice tersebut.
2. THE Sales_Invoice_Service SHALL menjaga invariant bahwa `Outstanding_Amount` setiap Sales_Invoice selalu sama dengan `Invoice_Amount` dikurangi `Paid_Amount`.
3. THE Sales_Payment_Service SHALL menjaga invariant bahwa `Paid_Amount` setiap Sales_Invoice tidak pernah melebihi `Invoice_Amount`.
4. WHILE suatu Sales_Invoice memiliki `Invoice_Payment_Status` bernilai `PAID`, THE Order_Service SHALL memastikan `Order.is_paid` Order asal bernilai true.
5. WHEN pencatatan Sales_Payment atau penyelesaian Sales_Settlement melibatkan beberapa perubahan data terkait, THE Sales_Payment_Service SHALL menjalankan seluruh perubahan dalam satu transaksi basis data atomik.
6. IF terjadi kegagalan di tengah pencatatan pembayaran atau penyelesaian settlement, THEN THE Sales_Payment_Service SHALL membatalkan seluruh perubahan pada transaksi tersebut sehingga tidak ada data parsial yang tersimpan.
