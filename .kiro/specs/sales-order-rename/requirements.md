# Requirements Document

## Introduction

Fitur ini melakukan penyelarasan penamaan domain penjualan pada proyek Laravel modular monolith (nwidart Modules) agar konsisten dengan konvensi domain Purchase. Saat ini penamaan tidak simetris:

- Modul `Purchase` memiliki `PurchaseOrder` + `PurchaseOrderItem` (sisi beli dari supplier).
- Modul `Order` memiliki `Order` + `OrderItem` (sisi jual ke customer) — tidak simetris dengan Purchase.
- Modul `Sales` hanya berisi `SalesReturn` + `SalesReturnItem`, tanpa entitas penjualan inti.

Keputusan user (Opsi A): me-rename class/namespace `Order` menjadi `SalesOrder` dan `OrderItem` menjadi `SalesOrderItem`, serta memindahkan entitas penjualan ke domain `Sales`, sehingga domain penjualan (`SalesOrder`, `SalesReturn`) berkumpul di bawah penamaan Sales, simetris dengan Purchase.

Fitur ini bersifat refactoring penamaan lintas modul. Tujuan utamanya adalah mempertahankan perilaku fungsional yang ada (tidak ada regresi) sambil menyelaraskan penamaan kode. Satu keputusan penting masih perlu dikonfirmasi: apakah refactoring mencakup penggantian nama tabel database (`orders` -> `sales_orders`) atau hanya rename class PHP dengan mempertahankan nama tabel via properti `$table`. Dokumen ini mengakomodasi kedua arah, dengan kecenderungan default ke "rename kode saja, tabel DB dipertahankan" untuk meminimalkan risiko.

Peta dampak yang sudah ditelusuri dan menjadi dasar requirements:

- **Modul Order (sumber)**: `Order`, `OrderItem`, `OrderRepository`, `OrderController`, `OrderService`, `OrderResource`, jobs `SyncStockJob` dan `CancelChannelOrderJob`, dan Service Provider modul.
- **Modul Outbound**: `PicklistItem`, `Packlist`, `ShipmentOrder` (relasi `belongsTo` Order); `PacklistItem` dan `PicklistItem` (relasi `belongsTo` OrderItem); service `ShipmentService`, `PicklistService`, `PacklistService`, `OutboundFulfillmentService` (mengimpor `Modules\Order\Models\Order`).
- **Modul Warranty**: `Warranty` (relasi `order()` `belongsTo` Order).
- **Modul Sales**: `SalesReturn` (relasi `order()` `belongsTo` Order).
- **Database / foreign key `order_id`** ada di tabel: `warranties`, `packlists`, `shipment_orders`, `picklist_items`, `sales_returns`, `order_items`. Terdapat migration UUID (`Modules/Order/database/migrations/..._change_order_ids_to_uuid.php`) yang mereferensikan tabel `orders`, `order_items`, `sales_returns` secara eksplisit. Tabel saat ini: `orders`, `order_items`.
- **Tes terdampak**: `tests/Feature/Order/OrderLifecycleTest.php`, `tests/Feature/Inbound/InboundE2ETest.php`.

Standar proyek (AGENTS.md) yang harus tetap dijaga setelah rename: Service-Repository pattern, `ApiResponse` trait + Eloquent Resources, Spatie Query Builder untuk listing, default pagination 10 per halaman, dan macro `allowedSearch` untuk Full-Text Search.

## Glossary

- **Sales_Order_Rename**: Inisiatif/sistem refactoring yang melakukan penyelarasan penamaan entitas penjualan, dirujuk sebagai aktor sistem dalam acceptance criteria.
- **SalesOrder**: Nama class model baru hasil rename dari `Order`, mewakili pesanan penjualan ke customer.
- **SalesOrderItem**: Nama class model baru hasil rename dari `OrderItem`, mewakili baris item pada pesanan penjualan.
- **Order (lama)**: Class model `Modules\Order\Models\Order` yang ada saat ini, yang akan di-rename.
- **OrderItem (lama)**: Class model `Modules\Order\Models\OrderItem` yang ada saat ini, yang akan di-rename.
- **Sales_Module**: Modul `Modules/Sales` tempat domain penjualan akan dikonsolidasikan.
- **Order_Module**: Modul `Modules/Order` yang saat ini menampung entitas Order.
- **Cross_Module_Reference**: Setiap penggunaan class `Order`/`OrderItem` di modul lain (Outbound, Warranty, Sales) melalui import namespace, type hint, atau relasi Eloquent.
- **Table_Strategy**: Keputusan strategi penamaan tabel database — "Rename_Table" (mengganti nama tabel fisik) atau "Keep_Table" (mempertahankan nama tabel via properti `$table`).
- **Rename_Table**: Strategi yang mengganti nama tabel `orders` -> `sales_orders` dan `order_items` -> `sales_order_items` melalui migration.
- **Keep_Table**: Strategi yang mempertahankan nama tabel fisik (`orders`, `order_items`) dengan mendeklarasikan properti `$table` pada model baru.
- **Foreign_Key_Column**: Kolom `order_id` pada tabel `warranties`, `packlists`, `shipment_orders`, `picklist_items`, `sales_returns`, dan `order_items`.
- **API_Consumer**: Integrator atau frontend yang mengonsumsi endpoint HTTP terkait order.
- **Test_Suite**: Kumpulan automated test proyek, termasuk test feature yang terdampak rename.

## Requirements

### Requirement 1: Rename Class dan Namespace Entitas Penjualan

**User Story:** Sebagai developer yang memelihara kode, saya ingin class `Order` dan `OrderItem` di-rename menjadi `SalesOrder` dan `SalesOrderItem`, sehingga penamaan entitas penjualan konsisten dengan konvensi domain Purchase.

#### Acceptance Criteria

1. THE Sales_Order_Rename SHALL me-rename class `Order` menjadi class bernama `SalesOrder`.
2. THE Sales_Order_Rename SHALL me-rename class `OrderItem` menjadi class bernama `SalesOrderItem`.
3. THE Sales_Order_Rename SHALL memindahkan class `SalesOrder` dan `SalesOrderItem` ke dalam Sales_Module.
4. THE Sales_Order_Rename SHALL memperbarui deklarasi namespace pada class `SalesOrder` dan `SalesOrderItem` agar sesuai dengan lokasi Sales_Module.
5. THE Sales_Order_Rename SHALL memperbarui konfigurasi autoloader (termasuk `composer.json` modul terkait) dan membersihkan cache autoloader sebagai bagian dari proses rename.
6. WHEN proses rename selesai, THE Test_Suite SHALL berhasil menyelesaikan autoload class `SalesOrder` dan `SalesOrderItem` tanpa error class-not-found.
7. THE Sales_Order_Rename SHALL mempertahankan relasi antara `SalesOrder` dan `SalesOrderItem` setara dengan relasi yang ada antara `Order` dan `OrderItem` sebelum rename.

### Requirement 2: Memperbarui Referensi Lintas Modul

**User Story:** Sebagai developer yang memelihara kode, saya ingin seluruh referensi ke `Order`/`OrderItem` di modul lain diperbarui ke `SalesOrder`/`SalesOrderItem`, sehingga tidak ada referensi yang putus setelah rename.

#### Acceptance Criteria

1. THE Sales_Order_Rename SHALL memperbarui setiap Cross_Module_Reference pada Modul Outbound (`PicklistItem`, `Packlist`, `ShipmentOrder`, `PacklistItem`, `ShipmentService`, `PicklistService`, `PacklistService`, `OutboundFulfillmentService`) agar menggunakan `SalesOrder` atau `SalesOrderItem`.
2. THE Sales_Order_Rename SHALL memperbarui Cross_Module_Reference pada Modul Warranty (relasi `order()` pada `Warranty`) agar merujuk class `SalesOrder`.
3. THE Sales_Order_Rename SHALL memperbarui Cross_Module_Reference pada Modul Sales (relasi `order()` pada `SalesReturn`) agar merujuk class `SalesOrder`.
4. THE Sales_Order_Rename SHALL memperbarui setiap statement import namespace yang merujuk `Modules\Order\Models\Order` dan `Modules\Order\Models\OrderItem` ke namespace baru.
5. WHEN seluruh referensi telah diperbarui, THE Sales_Order_Rename SHALL berupaya mempertahankan definisi Foreign_Key_Column `order_id` pada relasi tanpa mengubah nama kolom foreign key, dan SHALL menyelesaikan pembaruan referensi meskipun upaya tersebut gagal.
6. THE Test_Suite SHALL memvalidasi dan melaporkan setiap referensi ke class lama yang masih tersisa, terlepas dari status keberhasilan proses rename.

### Requirement 3: Keputusan Strategi Penamaan Tabel Database

**User Story:** Sebagai developer yang memelihara kode, saya ingin strategi penamaan tabel database diputuskan secara eksplisit, sehingga dampak terhadap data dan migration dipahami sebelum eksekusi.

#### Acceptance Criteria

1. THE Sales_Order_Rename SHALL mendokumentasikan satu Table_Strategy terpilih di antara Keep_Table dan Rename_Table sebelum perubahan kode dieksekusi.
2. WHERE Table_Strategy adalah Keep_Table, THE Sales_Order_Rename SHALL mendeklarasikan properti `$table` bernilai `orders` pada `SalesOrder` dan `order_items` pada `SalesOrderItem`.
3. WHERE Table_Strategy adalah Keep_Table, THE Sales_Order_Rename SHALL mempertahankan nama tabel fisik `orders` dan `order_items` tanpa perubahan.
4. WHERE Table_Strategy adalah Rename_Table, THE Sales_Order_Rename SHALL menyediakan migration yang mengganti nama tabel `orders` menjadi `sales_orders` dan `order_items` menjadi `sales_order_items`.
5. WHERE Table_Strategy adalah Rename_Table, THE Sales_Order_Rename SHALL menyediakan langkah rollback pada migration yang mengembalikan nama tabel ke `orders` dan `order_items`.
6. WHERE Table_Strategy adalah Rename_Table, THE Sales_Order_Rename SHALL memperbarui migration UUID yang mereferensikan tabel `orders`, `order_items`, dan `sales_returns` agar konsisten dengan nama tabel baru.
7. WHERE Table_Strategy adalah Rename_Table, THE Sales_Order_Rename SHALL mempertahankan integritas Foreign_Key_Column `order_id` pada tabel `warranties`, `packlists`, `shipment_orders`, `picklist_items`, dan `sales_returns`.

### Requirement 4: Memperbarui Lapisan Aplikasi pada Modul Order

**User Story:** Sebagai developer yang memelihara kode, saya ingin controller, repository, service, resource, jobs, dan provider yang terkait Order diperbarui mengikuti penamaan baru, sehingga seluruh lapisan aplikasi tetap berfungsi dan sesuai standar proyek.

#### Acceptance Criteria

1. THE Sales_Order_Rename SHALL memperbarui `OrderRepository`, `OrderController`, `OrderService`, dan `OrderResource` agar menggunakan class `SalesOrder` dan `SalesOrderItem`.
2. THE Sales_Order_Rename SHALL memperbarui jobs `SyncStockJob` dan `CancelChannelOrderJob` agar menggunakan class `SalesOrder` dan `SalesOrderItem`.
3. THE Sales_Order_Rename SHALL memperbarui Service Provider modul agar mendaftarkan binding dan path yang sesuai dengan lokasi class baru.
4. THE Sales_Order_Rename SHALL mempertahankan Service-Repository pattern, di mana interaksi database tetap berada di repository dan logika bisnis tetap berada di service.
5. THE Sales_Order_Rename SHALL mempertahankan penggunaan trait `ApiResponse` dan Eloquent Resource untuk respons API pada lapisan controller.
6. THE Sales_Order_Rename SHALL mempertahankan penggunaan Spatie Query Builder pada endpoint listing di dalam repository.
7. THE Sales_Order_Rename SHALL mempertahankan default pagination 10 item per halaman dengan input dinamis dari parameter `per_page`.
8. THE Sales_Order_Rename SHALL mempertahankan penggunaan macro `allowedSearch` pada endpoint pencarian.

### Requirement 5: Kontinuitas Endpoint API

**User Story:** Sebagai integrator API, saya ingin path endpoint penjualan diganti mengikuti penamaan domain Sales dengan kontrak data yang tetap setara, sehingga integrasi yang ada dapat menyesuaikan path baru tanpa perubahan struktur payload maupun respons.

#### Acceptance Criteria

1. THE Sales_Order_Rename SHALL mengganti prefix/path route endpoint penjualan dari `/orders` menjadi `/sales`.
2. THE Sales_Order_Rename SHALL menyediakan pemetaan terdokumentasi antara setiap path lama (`/orders...`) dan path baru (`/sales...`).
3. THE Sales_Order_Rename SHALL mempertahankan HTTP method setiap endpoint penjualan setara dengan sebelum rename.
4. THE Sales_Order_Rename SHALL mempertahankan struktur payload request setiap endpoint penjualan setara dengan sebelum rename.
5. WHEN sebuah request dikirim ke endpoint penjualan pada path baru setelah rename, THE Sales_Order_Rename SHALL mengembalikan struktur respons JSON yang setara dengan struktur sebelum rename untuk input yang sama.
6. THE Sales_Order_Rename SHALL mempertahankan nama field pada respons resource agar API_Consumer menerima skema data yang setara dengan sebelum rename.
7. WHERE perubahan path merupakan breaking change bagi integrasi yang ada, THE Sales_Order_Rename SHALL mengomunikasikan pemetaan path lama ke path baru kepada API_Consumer sebelum perubahan diberlakukan.

### Requirement 6: Tidak Ada Regresi Fungsional

**User Story:** Sebagai developer yang memelihara kode, saya ingin seluruh perilaku fungsional yang ada tetap berjalan setelah rename, sehingga refactoring tidak menimbulkan regresi.

#### Acceptance Criteria

1. WHEN alur lifecycle order dijalankan setelah rename, THE Sales_Order_Rename SHALL menghasilkan perilaku yang setara dengan sebelum rename.
2. WHEN proses cancel order marketplace dijalankan melalui `CancelChannelOrderJob`, THE Sales_Order_Rename SHALL mempertahankan perilaku pembatalan yang setara dengan sebelum rename.
3. WHEN alur outbound fulfillment (picklist, packlist, shipment) dijalankan, THE Sales_Order_Rename SHALL mempertahankan relasi ke pesanan penjualan dan perilaku fulfillment yang setara dengan sebelum rename.
4. WHEN relasi warranty terhadap pesanan penjualan diakses, THE Sales_Order_Rename SHALL mengembalikan data pesanan yang setara dengan sebelum rename.
5. WHEN alur sales return yang merujuk pesanan penjualan dijalankan, THE Sales_Order_Rename SHALL mempertahankan relasi dan perilaku yang setara dengan sebelum rename.

### Requirement 7: Pembaruan Automated Test

**User Story:** Sebagai developer yang memelihara kode, saya ingin test yang terdampak diperbarui mengikuti penamaan baru, sehingga test suite tetap valid dan memverifikasi perilaku.

#### Acceptance Criteria

1. THE Sales_Order_Rename SHALL memperbarui `tests/Feature/Order/OrderLifecycleTest.php` agar menggunakan class `SalesOrder` dan `SalesOrderItem`.
2. THE Sales_Order_Rename SHALL memperbarui `tests/Feature/Inbound/InboundE2ETest.php` agar menggunakan class dan factory yang sesuai dengan penamaan baru.
3. THE Sales_Order_Rename SHALL memperbarui factory dan seeder yang merujuk class lama agar merujuk `SalesOrder` dan `SalesOrderItem`.
4. WHEN Test_Suite dijalankan setelah rename, THE Test_Suite SHALL menyelesaikan eksekusi test yang terdampak tanpa kegagalan akibat referensi class atau factory yang hilang.

### Requirement 8: Strategi Migrasi dan Rollback

**User Story:** Sebagai developer yang memelihara kode, saya ingin tersedia strategi migrasi dan rollback yang jelas bila tabel di-rename, sehingga perubahan database dapat dibatalkan dengan aman.

#### Acceptance Criteria

1. WHERE Table_Strategy adalah Rename_Table, THE Sales_Order_Rename SHALL menyediakan urutan migrasi yang menjaga ketersediaan Foreign_Key_Column `order_id` selama proses migrasi.
2. WHERE Table_Strategy adalah Rename_Table, THE Sales_Order_Rename SHALL menyediakan prosedur rollback yang mengembalikan skema database ke kondisi sebelum migrasi, dan SHALL mencegah operasi rename tabel dimulai sebelum prosedur rollback tersebut tersedia.
3. WHERE Table_Strategy adalah Keep_Table, THE Sales_Order_Rename SHALL menyatakan bahwa tidak diperlukan migrasi skema database.
4. IF migrasi rename tabel gagal di tengah proses, THEN THE Sales_Order_Rename SHALL menyediakan langkah rollback yang dapat dijalankan untuk memulihkan skema sebelumnya.
