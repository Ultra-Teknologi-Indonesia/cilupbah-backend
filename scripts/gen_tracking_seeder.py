# -*- coding: utf-8 -*-
import yaml
d = yaml.safe_load(open("dist (2).yaml"))

# Indonesian function description per "METHOD path"
ID = {
"POST /login":"Login & dapatkan token akses (Sanctum)",
"GET /cashbank/payments/":"Ambil daftar pembayaran kas/bank (uang keluar)",
"GET /cashbank/payments/id":"Ambil detail satu pembayaran kas/bank",
"GET /cashbank/receives":"Ambil daftar penerimaan kas/bank (uang masuk)",
"GET /cashbank/receives/id":"Ambil detail satu penerimaan kas/bank",
"GET /marketplace/store/":"Ambil semua toko marketplace yang terhubung",
"GET /contact/category/":"Ambil daftar kategori kontak",
"DELETE /contacts/":"Hapus kontak",
"GET /contacts/":"Ambil semua kontak",
"POST /contacts/":"Buat/ubah kontak",
"GET /contacts/customers-suppliers/":"Ambil kontak yang sekaligus customer & supplier",
"GET /contacts/customers/":"Ambil daftar customer",
"GET /contacts/suppliers/":"Ambil daftar supplier/vendor",
"GET /contacts/{id}":"Ambil detail satu kontak",
"GET /couriers":"Ambil semua kurir",
"GET /couriers/tenant/{id}":"Ambil kurir milik tenant tertentu",
"GET /couriers/{id}":"Ambil detail satu kurir",
"GET /inventory/":"Ambil stok semua produk",
"GET /inventory/activity/":"Ambil riwayat pergerakan stok produk",
"DELETE /inventory/adjustments/":"Hapus dokumen penyesuaian stok",
"GET /inventory/adjustments/":"Ambil semua penyesuaian stok",
"POST /inventory/adjustments/":"Buat/ubah penyesuaian stok",
"GET /inventory/adjustments/{id}":"Ambil detail penyesuaian stok",
"POST /inventory/catalog/set-master":"Set produk dari 'In Review' menjadi 'Master'",
"GET /inventory/catalog/{group_id}":"Ambil katalog item per grup",
"GET /inventory/items/by-bill/{doc_id}":"Ambil detail item retur pembelian per bill",
"GET /inventory/items/by-invoice/{invoice_id}":"Ambil daftar item per nomor invoice",
"GET /inventory/items/by-transfer/{item_transfer_id}":"Ambil daftar produk yang akan diterima per nomor transfer",
"POST /inventory/items/group/merge-catalog":"Gabungkan item serupa dalam katalog",
"GET /inventory/items/item-on-stock":"Ambil daftar item untuk ditransfer",
"GET /inventory/items/received":"Ambil daftar item yang sudah diterima",
"POST /inventory/items/received/author":"Tugaskan staf untuk putaway",
"POST /inventory/items/received/auto-putaway":"Set item untuk auto-putaway",
"POST /inventory/items/received/finish-putaway":"Tandai proses putaway selesai",
"GET /inventory/items/received/item/{putaway_id}":"Ambil daftar item putaway",
"POST /inventory/items/received/putaway":"Letakkan item ke rak (putaway)",
"POST /inventory/items/split-item":"Pisah item (split) jadi unit lebih kecil",
"POST /inventory/items/to-adjust/":"Ambil cost & stok item untuk penyesuaian",
"GET /inventory/items/to-buy":"Ambil daftar produk yang perlu dibeli (untuk PO)",
"GET /inventory/items/to-sales-return":"Ambil daftar item retur penjualan",
"GET /inventory/items/to-sell/{location_id}":"Ambil item yang bisa dijual per lokasi",
"GET /inventory/items/to-stock/":"Ambil semua item yang stoknya perlu disesuaikan",
"GET /inventory/items/to-stock/{location_id}":"Ambil item untuk distok per lokasi",
"GET /inventory/items/{id}/batch-number":"Ambil nomor batch item",
"GET /inventory/need-restock/":"Ambil produk yang perlu restock",
"GET /inventory/out-of-stock-in-order/":"Ambil produk habis stok yang ada di order",
"GET /inventory/putaway/all":"Ambil ID putaway",
"GET /inventory/putaway/completed":"Daftar putaway yang sudah selesai",
"GET /inventory/putaway/not-start":"Daftar putaway yang belum dimulai",
"GET /inventory/putaway/processed":"Daftar putaway yang sedang diproses",
"GET /inventory/reserved/":"Ambil daftar stok yang direservasi",
"POST /inventory/reserved/":"Buat reservasi stok item",
"GET /inventory/reserved/{id}":"Ambil detail reservasi stok",
"POST /inventory/revaluations/":"Buat/ubah penyesuaian nilai (revaluasi) stok",
"GET /inventory/stock-opname":"Ambil daftar stock opname semua status",
"POST /inventory/stock-opname":"Buat daftar item untuk diopname",
"GET /inventory/stock-opname/bins":"Ambil semua bin per lokasi",
"GET /inventory/stock-opname/columns":"Ambil lokasi rak per kolom",
"POST /inventory/stock-opname/finalize":"Selesaikan opname & push stok final",
"GET /inventory/stock-opname/floors":"Ambil lokasi rak per lantai",
"GET /inventory/stock-opname/items":"Ambil semua item untuk diopname",
"GET /inventory/stock-opname/items/filtered":"Ambil item opname terfilter per lokasi rak",
"GET /inventory/stock-opname/rows":"Ambil lokasi rak per baris",
"GET /inventory/stock-opname/{opname_header_id}":"Ambil stok real-time saat opname berjalan",
"GET /inventory/transfer/delivery":"Cetak laporan surat jalan transfer",
"POST /inventory/transfer/mark-printed":"Tandai transfer item sudah dicetak",
"DELETE /inventory/transfers/":"Hapus transfer stok",
"POST /inventory/transfers/":"Buat transfer stok (masuk/keluar)",
"GET /inventory/transfers/all-transit":"Ambil semua nomor transaksi transfer transit",
"GET /inventory/transfers/in":"Ambil transfer stok masuk",
"GET /inventory/transfers/out":"Ambil transfer stok keluar",
"GET /inventory/transfers/out-finished":"Ambil transfer yang sudah selesai/diterima",
"GET /inventory/transfers/transit":"Ambil transfer stok dalam perjalanan (transit)",
"GET /inventory/transfers/{id}":"Ambil detail transfer stok",
"GET /accounts/lookup/all":"Ambil daftar akun (Chart of Accounts)",
"GET /journal/":"Ambil semua jurnal",
"GET /journal/manual-journal/":"Ambil semua jurnal manual",
"POST /journal/manual-journal/":"Buat/ubah jurnal manual",
"GET /journal/{id}":"Ambil detail jurnal per ID",
"DELETE /locations/":"Hapus lokasi",
"GET /locations/":"Ambil semua lokasi",
"POST /locations/":"Buat/ubah lokasi & denah rak",
"GET /locations/bin/{location_id}":"Ambil bin per lokasi",
"GET /locations/pos":"Ambil lokasi yang punya outlet POS",
"GET /locations/store/":"Ambil pemetaan lokasi ke toko",
"GET /locations/{id}":"Ambil detail lokasi",
"GET /wms/default-bin/{location_id}":"Ambil bin default per lokasi",
"POST /inventory/catalog/":"Buat/ubah produk",
"GET /inventory/categories/category-map/{id}":"Ambil pemetaan kategori ke marketplace",
"GET /inventory/categories/item-categories/":"Ambil semua kategori",
"GET /inventory/categories/item-categories/information/{id}/":"Ambil informasi kategori",
"GET /inventory/categories/{channel_id}/store-categories/{store_id}":"Ambil kategori toko per channel",
"GET /inventory/categories/{id}/attributes-value/":"Ambil nilai atribut kategori",
"GET /inventory/categories/{id}/attributes/":"Ambil atribut kategori",
"GET /inventory/categories/{id}/variations/":"Ambil varian kategori",
"GET /inventory/internal-price-list/":"Ambil semua harga produk (price list internal)",
"GET /inventory/item-bundles/":"Ambil semua bundle produk",
"DELETE /inventory/items/":"Hapus produk",
"GET /inventory/items/":"Ambil semua grup produk",
"POST /inventory/items/":"Buat/ubah bundle produk",
"POST /inventory/items/all-stocks/":"Ambil stok produk per banyak ID",
"POST /inventory/items/archive/":"Arsipkan produk",
"GET /inventory/items/archived/":"Ambil semua produk terarsip",
"GET /inventory/items/by-sku/{sku}":"Ambil produk per SKU",
"GET /inventory/items/channel-category-attributes/":"Ambil semua atribut kategori channel",
"GET /inventory/items/channel-category-tree/":"Ambil pohon kategori channel",
"GET /inventory/items/group/{id}":"Ambil grup produk",
"DELETE /inventory/items/item-variant/":"Hapus varian item",
"GET /inventory/items/masters":"Ambil semua produk master",
"POST /inventory/items/prices/":"Ambil harga produk per banyak ID",
"POST /inventory/items/restore/":"Pulihkan produk dari arsip",
"GET /inventory/items/reviews/":"Ambil semua produk dalam status review",
"GET /inventory/items/{id}":"Ambil detail produk",
"POST /inventory/price-list/":"Ubah harga produk",
"DELETE /inventory/promotions/":"Hapus promosi",
"GET /inventory/promotions/":"Ambil semua promosi",
"POST /inventory/promotions/":"Buat promosi",
"GET /inventory/promotions/{id}":"Ambil detail promosi",
"GET /inventory/search-brands/":"Ambil semua merek (brand)",
"POST /inventory/upload-image":"Unggah gambar produk",
"GET /variations":"Ambil semua variasi (variant) produk",
"GET /blibli/pickupPoints":"Ambil titik pickup Blibli",
"GET /inventory/catalog/for-listing/{id}":"Ambil data produk untuk listing channel",
"POST /inventory/catalog/listing":"Buat/ubah listing produk",
"POST /inventory/catalog/upload":"Unggah listing produk ke channel",
"GET /inventory/categories/channel-categories/{parent_id}":"Ambil kategori channel",
"GET /inventory/items/errors/":"Ambil daftar listing yang gagal upload",
"GET /shopee/logistics":"Ambil opsi logistik Shopee",
"GET /tokopedia/showcases":"Ambil etalase (showcase) Tokopedia",
"DELETE /purchase/":"Hapus retur pembelian",
"DELETE /purchase/bills/":"Hapus bill (tagihan pembelian)",
"GET /purchase/bills/":"Ambil semua bill",
"POST /purchase/bills/":"Buat/ubah bill",
"GET /purchase/bills/for-return":"Ambil nomor bill untuk diretur",
"GET /purchase/bills/overdue/":"Ambil bill yang jatuh tempo",
"GET /purchase/bills/unpaid/":"Ambil bill yang belum lunas",
"GET /purchase/bills/{id}":"Ambil detail bill",
"DELETE /purchase/orders/":"Hapus purchase order",
"GET /purchase/orders/":"Ambil semua purchase order",
"POST /purchase/orders/":"Buat/ubah purchase order",
"GET /purchase/orders/progress":"Ambil progres penerimaan semua PO",
"GET /purchase/orders/{id}":"Ambil detail purchase order",
"DELETE /purchase/payments/":"Hapus pembayaran bill",
"GET /purchase/payments/":"Ambil semua pembayaran bill",
"POST /purchase/payments/":"Buat/ubah pembayaran bill",
"GET /purchase/payments/{id}":"Ambil detail pembayaran bill",
"GET /purchase/purchase-returns/":"Ambil semua retur pembelian",
"POST /purchase/purchase-returns/":"Buat/ubah retur pembelian",
"GET /purchase/purchase-returns/unpaid/":"Ambil retur pembelian yang belum lunas",
"GET /purchase/purchase-returns/{id}":"Ambil detail retur pembelian",
"DELETE /purchase/return-settlements/":"Hapus settlement retur pembelian",
"GET /purchase/return-settlements/bills/":"Ambil semua settlement bill retur",
"POST /purchase/return-settlements/bills/":"Buat/ubah settlement bill retur",
"GET /purchase/return-settlements/bills/{id}":"Ambil detail settlement bill retur",
"GET /purchase/return-settlements/refunds/":"Ambil semua refund settlement retur",
"POST /purchase/return-settlements/refunds/":"Buat/ubah refund settlement retur",
"GET /purchase/return-settlements/refunds/{id}":"Ambil detail refund settlement retur",
"POST /purchase/serial-number/mark-printed":"Cetak barcode produk untuk putaway",
"GET /purchase/serial-number/wms/{bill_detail_id}":"Ambil nomor seri/batch item per bill detail",
"GET /region/cities/?province_id={province_id}":"Ambil daftar kota per provinsi",
"GET /region/districts/?city_id={city_id}":"Ambil daftar kecamatan per kota",
"GET /region/provinces":"Ambil daftar provinsi",
"GET /region/subdistricts/?district_id={district_id}":"Ambil daftar kelurahan per kecamatan",
"GET /lazada/get-document/":"Cetak invoice/label Lazada",
"GET /reports/adjustment":"Cetak laporan penyesuaian stok",
"GET /reports/consign":"Cetak bill terima produk konsinyasi",
"GET /reports/invoice":"Cetak invoice",
"GET /reports/item-receive-notplace":"Cetak daftar item diterima yang belum diletakkan",
"GET /reports/lable/print/":"Cetak label pengiriman",
"GET /reports/purchaseorder/":"Cetak detail purchase order",
"GET /reports/putaway":"Cetak laporan putaway",
"GET /reports/receive":"Cetak bill terima untuk purchase order",
"GET /reports/stock-opname":"Cetak daftar item untuk opname",
"GET /reports/wms/pick-list":"Cetak picklist item",
"GET /reports/wms/shipping-manifest":"Cetak bukti pengiriman (shipping manifest)",
"GET /reports/shipping-label/":"Cetak label pengiriman",
"POST /inventory/items/complete-return/":"Set item jadi tidak diretur (batal retur)",
"POST /inventory/items/reject-return/":"Tolak permintaan retur",
"POST /inventory/items/to-return/":"Terima retur penjualan",
"DELETE /sales/":"Hapus retur/invoice penjualan",
"GET /sales/":"Ambil semua data penjualan",
"GET /sales/invoices/":"Ambil semua invoice penjualan",
"POST /sales/invoices/":"Buat/ubah invoice penjualan",
"GET /sales/invoices/for-return-wms/{contact_id}":"Ambil ID invoice untuk retur penjualan",
"GET /sales/invoices/overdue/":"Ambil invoice yang jatuh tempo",
"GET /sales/invoices/summary/":"Ambil ringkasan invoice per toko",
"GET /sales/invoices/unpaid/":"Ambil invoice yang belum lunas",
"GET /sales/invoices/{id}":"Ambil detail invoice",
"DELETE /sales/orders/":"Hapus sales order",
"POST /sales/orders/":"Buat/ubah sales order",
"GET /sales/orders/cancel/":"Ambil sales order yang dibatalkan",
"GET /sales/orders/completed/":"Ambil order selesai dari semua channel",
"POST /sales/orders/delete-canceled":"Hapus item order yang dibatalkan",
"GET /sales/orders/failed/":"Ambil sales order yang gagal",
"POST /sales/orders/mark-as-complete":"Tandai sales order selesai",
"GET /sales/orders/returned-list/":"Ambil sales order yang diretur",
"POST /sales/orders/save-airwaybill/":"Perbarui AWB sales order",
"POST /sales/orders/save-received-date":"Perbarui tanggal terima sales order",
"POST /sales/orders/set-as-paid":"Set sales order menjadi lunas",
"GET /sales/orders/{id}":"Ambil detail sales order",
"GET /sales/packlists/":"Ambil semua packlist",
"POST /sales/packlists/create-invoice":"Konversi sales order menjadi invoice",
"POST /sales/packlists/create-invoice-payment":"Konversi sales order jadi invoice + pembayaran",
"GET /sales/packlists/shipped/":"Ambil sales order yang sudah dikirim",
"GET /sales/packlists/{id}":"Ambil detail packlist",
"DELETE /sales/payments/":"Hapus pembayaran invoice",
"GET /sales/payments/":"Ambil semua pembayaran invoice",
"POST /sales/payments/":"Buat/ubah pembayaran invoice",
"GET /sales/payments/{id}":"Ambil detail pembayaran invoice",
"POST /sales/picklists/items-to-pick":"Ambil daftar item untuk dipick",
"POST /sales/picklists/items-to-pick/":"Ambil daftar item untuk dipick (varian)",
"DELETE /sales/picklists/to-ship/":"Hapus picklist",
"GET /sales/picklists/{picklist_id}":"Ambil item dalam picklist",
"POST /sales/request-awb-order/":"Minta AWB untuk order",
"DELETE /sales/return-settlements/":"Hapus settlement retur penjualan",
"GET /sales/return-settlements/":"Ambil semua settlement retur penjualan",
"GET /sales/return-settlements/invoices/":"Ambil semua invoice settlement retur",
"POST /sales/return-settlements/invoices/":"Buat/ubah invoice settlement retur",
"GET /sales/return-settlements/invoices/{id}":"Ambil detail invoice settlement retur",
"GET /sales/return-settlements/refunds/":"Ambil semua refund settlement retur",
"POST /sales/return-settlements/refunds/":"Buat/ubah refund settlement retur",
"GET /sales/return-settlements/refunds/{id}":"Ambil detail refund settlement retur",
"GET /sales/returns/items/":"Ambil semua item retur",
"GET /sales/returns/items/rejected/":"Ambil order retur yang ditolak",
"GET /sales/returns/items/resolved/":"Ambil order retur yang disetujui/selesai",
"GET /sales/returns/items/unprocessed/wms":"Ambil retur penjualan yang belum diproses",
"GET /sales/sales-returns/":"Ambil semua retur penjualan",
"POST /sales/sales-returns/":"Terima retur penjualan",
"GET /sales/sales-returns/unpaid/":"Ambil retur penjualan yang belum lunas",
"GET /sales/sales-returns/{id}":"Ambil detail retur penjualan",
"GET /sales/settlements/":"Ambil semua settlement penjualan",
"GET /sales/settlements/{id}":"Ambil detail settlement penjualan",
"POST /sales/shipments/":"Set item sudah diterima kurir",
"POST /sales/shipments/orders/":"Buat order pengiriman (shipment)",
"GET /sales/shipments/{shipment_header_id}":"Ambil item siap kirim per jadwal shipment",
"GET /sales/unfullfilled/":"Ambil packlist yang belum terpenuhi",
"GET /lazada/get-shipment-providers/{storeId}/":"Ambil info penyedia pengiriman Lazada",
"GET /store-locations/":"Ambil lokasi toko",
"GET /systemsetting/account-mapping":"Ambil pemetaan akun akuntansi",
"GET /systemsetting/sales-return-setting":"Ambil setting retur penjualan",
"POST /systemsetting/sales-return-setting":"Buat setting retur penjualan",
"GET /systemsetting/users/":"Ambil daftar user/staf gudang",
"POST /systemsetting/webhook":"Buat/ubah registrasi webhook",
"GET /taxes/":"Ambil daftar pajak",
"GET /wms/couriers":"Ambil daftar kurir WMS",
"GET /wms/employee/{NIKorEmail}":"Ambil info staf gudang per NIK/email",
"POST /wms/order/getOrderByNo/":"Ambil sales order yang itemnya akan dipick",
"GET /wms/sales/order/ready-to-ship":"Ambil order yang perlu dikirim ke kurir",
"POST /wms/sales/orders/change-location/":"Ubah lokasi gudang untuk order",
"GET /wms/sales/orders/empty-stock/":"Ambil order yang stoknya kosong",
"GET /wms/sales/orders/failed-pick":"Ambil order yang batal dipick",
"GET /wms/sales/orders/finish-pick/":"Ambil order yang selesai dipick",
"GET /wms/sales/orders/ready-to-pick/":"Ambil order siap dipick",
"GET /wms/sales/orders/ready-to-process/":"Ambil order siap diproses",
"GET /wms/sales/orders/request-cancel/":"Ambil order yang diminta batal oleh customer",
"POST /wms/sales/packlist":"Buat packlist",
"POST /wms/sales/packlist/mark-as-complete/":"Tandai order siap kirim (selesai packing)",
"GET /wms/sales/packlist/scan-order":"Ambil daftar item untuk dipacking",
"POST /wms/sales/packlist/update-qty-packed":"Perbarui qty item yang sudah dipacking",
"POST /wms/sales/packlist/verify-barcode/":"Verifikasi item/SKU/barcode/serial/batch",
"GET /wms/sales/packlists/finish-pack/":"Ambil order yang selesai packing",
"GET /wms/sales/packlists/process/":"Ambil order yang sedang proses packing",
"POST /wms/sales/picklists/":"Buat picklist / set picklist selesai",
"POST /wms/sales/picklists/change-picker/":"Ganti staf picker",
"GET /wms/sales/picklists/confirm-pick/":"Ambil order yang sedang proses picking",
"POST /wms/sales/ready-to-pick":"Pindahkan order ke status 'ready to pick'",
"POST /wms/sales/ready-to-process":"Pindahkan order ke status 'ready to process'",
"GET /wms/sales/shipments/all":"Ambil semua jadwal shipment kurir reguler",
"GET /wms/sales/shipments/completed/{shipment_type}/{courierIds}":"Ambil shipment yang sudah dalam pengiriman",
"GET /wms/sales/shipments/instant/all":"Ambil semua jadwal shipment kurir instant",
"POST /wms/sales/shipments/orders/":"Ambil AWB untuk order",
"GET /wms/sales/shipments/{courier_new_id}":"Ambil shipment per kurir tertentu",
"GET /wms/sales/shipped/":"Ambil order yang sudah dikirim kurir",
"POST /wms/scan-shipment":"Ambil jadwal shipment via scan nomor shipment",
"POST /wms/shipment-detail/":"Tambah order ke jadwal shipment",
"POST /wms/shipments/":"Buat jadwal shipment kurir reguler",
"POST /wms/shipments/get-order/":"Perbarui qty item yang sudah diserahkan ke kurir",
"POST /wms/shipments/instant-courier/":"Buat jadwal shipment kurir instant",
"POST /webhooks/invoice":"Webhook: notifikasi invoice baru",
"POST /webhooks/payment":"Webhook: notifikasi update pembayaran",
"POST /webhooks/price":"Webhook: notifikasi update harga",
"POST /webhooks/product":"Webhook: notifikasi produk baru",
"POST /webhooks/purchaseorder":"Webhook: notifikasi PO baru",
"POST /webhooks/salesorder":"Webhook: notifikasi update sales order",
"POST /webhooks/salesreturn":"Webhook: notifikasi retur penjualan baru",
"POST /webhooks/stock":"Webhook: notifikasi update stok",
"POST /webhooks/stocktransfer":"Webhook: notifikasi transfer stok baru",
}

# Status per "METHOD path": done/partial/todo
ST = {
"POST /login":"done","GET /cashbank/payments/":"todo","GET /cashbank/payments/id":"todo","GET /cashbank/receives":"todo","GET /cashbank/receives/id":"todo",
"GET /marketplace/store/":"done",
"GET /contact/category/":"todo","DELETE /contacts/":"todo","GET /contacts/":"todo","POST /contacts/":"todo","GET /contacts/customers-suppliers/":"todo","GET /contacts/customers/":"todo","GET /contacts/suppliers/":"partial","GET /contacts/{id}":"partial",
"GET /couriers":"done","GET /couriers/tenant/{id}":"todo","GET /couriers/{id}":"done",
"GET /inventory/":"done","GET /inventory/activity/":"done","DELETE /inventory/adjustments/":"done","GET /inventory/adjustments/":"done","POST /inventory/adjustments/":"done","GET /inventory/adjustments/{id}":"done",
"POST /inventory/catalog/set-master":"partial","GET /inventory/catalog/{group_id}":"partial",
"GET /inventory/items/by-bill/{doc_id}":"todo","GET /inventory/items/by-invoice/{invoice_id}":"todo","GET /inventory/items/by-transfer/{item_transfer_id}":"partial",
"POST /inventory/items/group/merge-catalog":"done","GET /inventory/items/item-on-stock":"partial","GET /inventory/items/received":"done",
"POST /inventory/items/received/author":"done","POST /inventory/items/received/auto-putaway":"done","POST /inventory/items/received/finish-putaway":"done","GET /inventory/items/received/item/{putaway_id}":"done","POST /inventory/items/received/putaway":"done",
"POST /inventory/items/split-item":"todo","POST /inventory/items/to-adjust/":"partial","GET /inventory/items/to-buy":"done","GET /inventory/items/to-sales-return":"todo","GET /inventory/items/to-sell/{location_id}":"todo","GET /inventory/items/to-stock/":"done","GET /inventory/items/to-stock/{location_id}":"done","GET /inventory/items/{id}/batch-number":"todo",
"GET /inventory/need-restock/":"todo","GET /inventory/out-of-stock-in-order/":"todo",
"GET /inventory/putaway/all":"done","GET /inventory/putaway/completed":"done","GET /inventory/putaway/not-start":"done","GET /inventory/putaway/processed":"done",
"GET /inventory/reserved/":"done","POST /inventory/reserved/":"done","GET /inventory/reserved/{id}":"done","POST /inventory/revaluations/":"todo",
"GET /inventory/stock-opname":"done","POST /inventory/stock-opname":"done","GET /inventory/stock-opname/bins":"done","GET /inventory/stock-opname/columns":"done","POST /inventory/stock-opname/finalize":"done","GET /inventory/stock-opname/floors":"done","GET /inventory/stock-opname/items":"done","GET /inventory/stock-opname/items/filtered":"partial","GET /inventory/stock-opname/rows":"done","GET /inventory/stock-opname/{opname_header_id}":"done",
"GET /inventory/transfer/delivery":"todo","POST /inventory/transfer/mark-printed":"todo","DELETE /inventory/transfers/":"partial","POST /inventory/transfers/":"done","GET /inventory/transfers/all-transit":"done","GET /inventory/transfers/in":"done","GET /inventory/transfers/out":"done","GET /inventory/transfers/out-finished":"partial","GET /inventory/transfers/transit":"done","GET /inventory/transfers/{id}":"done",
"GET /accounts/lookup/all":"todo","GET /journal/":"todo","GET /journal/manual-journal/":"todo","POST /journal/manual-journal/":"todo","GET /journal/{id}":"todo",
"DELETE /locations/":"done","GET /locations/":"done","POST /locations/":"done","GET /locations/bin/{location_id}":"done","GET /locations/pos":"todo","GET /locations/store/":"done","GET /locations/{id}":"done","GET /wms/default-bin/{location_id}":"done",
"POST /inventory/catalog/":"done","GET /inventory/categories/category-map/{id}":"done","GET /inventory/categories/item-categories/":"done","GET /inventory/categories/item-categories/information/{id}/":"done","GET /inventory/categories/{channel_id}/store-categories/{store_id}":"done","GET /inventory/categories/{id}/attributes-value/":"done","GET /inventory/categories/{id}/attributes/":"done","GET /inventory/categories/{id}/variations/":"done",
"GET /inventory/internal-price-list/":"done","GET /inventory/item-bundles/":"done","DELETE /inventory/items/":"done","GET /inventory/items/":"done","POST /inventory/items/":"done","POST /inventory/items/all-stocks/":"done","POST /inventory/items/archive/":"done","GET /inventory/items/archived/":"done","GET /inventory/items/by-sku/{sku}":"done","GET /inventory/items/channel-category-attributes/":"done","GET /inventory/items/channel-category-tree/":"done","GET /inventory/items/group/{id}":"done","DELETE /inventory/items/item-variant/":"done","GET /inventory/items/masters":"done","POST /inventory/items/prices/":"done","POST /inventory/items/restore/":"done","GET /inventory/items/reviews/":"done","GET /inventory/items/{id}":"done",
"POST /inventory/price-list/":"done","DELETE /inventory/promotions/":"done","GET /inventory/promotions/":"done","POST /inventory/promotions/":"done","GET /inventory/promotions/{id}":"done","GET /inventory/search-brands/":"done","POST /inventory/upload-image":"done","GET /variations":"done",
"GET /blibli/pickupPoints":"todo","GET /inventory/catalog/for-listing/{id}":"done","POST /inventory/catalog/listing":"done","POST /inventory/catalog/upload":"done","GET /inventory/categories/channel-categories/{parent_id}":"done","GET /inventory/items/errors/":"done","GET /shopee/logistics":"todo","GET /tokopedia/showcases":"todo",
"DELETE /purchase/":"todo","DELETE /purchase/bills/":"todo","GET /purchase/bills/":"todo","POST /purchase/bills/":"todo","GET /purchase/bills/for-return":"todo","GET /purchase/bills/overdue/":"todo","GET /purchase/bills/unpaid/":"todo","GET /purchase/bills/{id}":"todo",
"DELETE /purchase/orders/":"done","GET /purchase/orders/":"done","POST /purchase/orders/":"done","GET /purchase/orders/progress":"partial","GET /purchase/orders/{id}":"done",
"DELETE /purchase/payments/":"todo","GET /purchase/payments/":"todo","POST /purchase/payments/":"todo","GET /purchase/payments/{id}":"todo",
"GET /purchase/purchase-returns/":"todo","POST /purchase/purchase-returns/":"todo","GET /purchase/purchase-returns/unpaid/":"todo","GET /purchase/purchase-returns/{id}":"todo",
"DELETE /purchase/return-settlements/":"todo","GET /purchase/return-settlements/bills/":"todo","POST /purchase/return-settlements/bills/":"todo","GET /purchase/return-settlements/bills/{id}":"todo","GET /purchase/return-settlements/refunds/":"todo","POST /purchase/return-settlements/refunds/":"todo","GET /purchase/return-settlements/refunds/{id}":"todo",
"POST /purchase/serial-number/mark-printed":"todo","GET /purchase/serial-number/wms/{bill_detail_id}":"todo",
"GET /region/cities/?province_id={province_id}":"done","GET /region/districts/?city_id={city_id}":"done","GET /region/provinces":"done","GET /region/subdistricts/?district_id={district_id}":"done",
"GET /lazada/get-document/":"todo","GET /reports/adjustment":"todo","GET /reports/consign":"todo","GET /reports/invoice":"todo","GET /reports/item-receive-notplace":"todo","GET /reports/lable/print/":"todo","GET /reports/purchaseorder/":"todo","GET /reports/putaway":"todo","GET /reports/receive":"todo","GET /reports/stock-opname":"todo","GET /reports/wms/pick-list":"todo","GET /reports/wms/shipping-manifest":"todo","GET /reports/shipping-label/":"todo",
"POST /inventory/items/complete-return/":"done","POST /inventory/items/reject-return/":"done","POST /inventory/items/to-return/":"done",
"DELETE /sales/":"done","GET /sales/":"done","GET /sales/invoices/":"todo","POST /sales/invoices/":"todo","GET /sales/invoices/for-return-wms/{contact_id}":"todo","GET /sales/invoices/overdue/":"todo","GET /sales/invoices/summary/":"todo","GET /sales/invoices/unpaid/":"todo","GET /sales/invoices/{id}":"todo",
"DELETE /sales/orders/":"done","POST /sales/orders/":"done","GET /sales/orders/cancel/":"todo","GET /sales/orders/completed/":"todo","POST /sales/orders/delete-canceled":"todo","GET /sales/orders/failed/":"todo","POST /sales/orders/mark-as-complete":"todo","GET /sales/orders/returned-list/":"todo","POST /sales/orders/save-airwaybill/":"todo","POST /sales/orders/save-received-date":"todo","POST /sales/orders/set-as-paid":"todo","GET /sales/orders/{id}":"done",
"GET /sales/packlists/":"partial","POST /sales/packlists/create-invoice":"todo","POST /sales/packlists/create-invoice-payment":"todo","GET /sales/packlists/shipped/":"partial","GET /sales/packlists/{id}":"partial",
"DELETE /sales/payments/":"todo","GET /sales/payments/":"todo","POST /sales/payments/":"todo","GET /sales/payments/{id}":"todo",
"POST /sales/picklists/items-to-pick":"partial","POST /sales/picklists/items-to-pick/":"partial","DELETE /sales/picklists/to-ship/":"partial","GET /sales/picklists/{picklist_id}":"partial","POST /sales/request-awb-order/":"todo",
"DELETE /sales/return-settlements/":"todo","GET /sales/return-settlements/":"todo","GET /sales/return-settlements/invoices/":"todo","POST /sales/return-settlements/invoices/":"todo","GET /sales/return-settlements/invoices/{id}":"todo","GET /sales/return-settlements/refunds/":"todo","POST /sales/return-settlements/refunds/":"todo","GET /sales/return-settlements/refunds/{id}":"todo",
"GET /sales/returns/items/":"partial","GET /sales/returns/items/rejected/":"partial","GET /sales/returns/items/resolved/":"partial","GET /sales/returns/items/unprocessed/wms":"done",
"GET /sales/sales-returns/":"done","POST /sales/sales-returns/":"done","GET /sales/sales-returns/unpaid/":"todo","GET /sales/sales-returns/{id}":"done",
"GET /sales/settlements/":"todo","GET /sales/settlements/{id}":"todo","POST /sales/shipments/":"partial","POST /sales/shipments/orders/":"partial","GET /sales/shipments/{shipment_header_id}":"partial","GET /sales/unfullfilled/":"todo",
"GET /lazada/get-shipment-providers/{storeId}/":"todo","GET /store-locations/":"todo","GET /systemsetting/account-mapping":"todo","GET /systemsetting/sales-return-setting":"todo","POST /systemsetting/sales-return-setting":"todo","GET /systemsetting/users/":"done","POST /systemsetting/webhook":"done","GET /taxes/":"done",
"GET /wms/couriers":"done","GET /wms/employee/{NIKorEmail}":"done","POST /wms/order/getOrderByNo/":"partial","GET /wms/sales/order/ready-to-ship":"done","POST /wms/sales/orders/change-location/":"done","GET /wms/sales/orders/empty-stock/":"done","GET /wms/sales/orders/failed-pick":"done","GET /wms/sales/orders/finish-pick/":"done","GET /wms/sales/orders/ready-to-pick/":"done","GET /wms/sales/orders/ready-to-process/":"done","GET /wms/sales/orders/request-cancel/":"done",
"POST /wms/sales/packlist":"done","POST /wms/sales/packlist/mark-as-complete/":"done","GET /wms/sales/packlist/scan-order":"partial","POST /wms/sales/packlist/update-qty-packed":"done","POST /wms/sales/packlist/verify-barcode/":"done","GET /wms/sales/packlists/finish-pack/":"partial","GET /wms/sales/packlists/process/":"partial",
"POST /wms/sales/picklists/":"done","POST /wms/sales/picklists/change-picker/":"done","GET /wms/sales/picklists/confirm-pick/":"partial","POST /wms/sales/ready-to-pick":"partial","POST /wms/sales/ready-to-process":"partial",
"GET /wms/sales/shipments/all":"done","GET /wms/sales/shipments/completed/{shipment_type}/{courierIds}":"todo","GET /wms/sales/shipments/instant/all":"todo","POST /wms/sales/shipments/orders/":"done","GET /wms/sales/shipments/{courier_new_id}":"partial","GET /wms/sales/shipped/":"done",
"POST /wms/scan-shipment":"done","POST /wms/shipment-detail/":"done","POST /wms/shipments/":"done","POST /wms/shipments/get-order/":"todo","POST /wms/shipments/instant-courier/":"todo",
"POST /webhooks/invoice":"done","POST /webhooks/payment":"done","POST /webhooks/price":"done","POST /webhooks/product":"done","POST /webhooks/purchaseorder":"done","POST /webhooks/salesorder":"done","POST /webhooks/salesreturn":"done","POST /webhooks/stock":"done","POST /webhooks/stocktransfer":"done",
}


# ---- Bangun rows dari spec & validasi kelengkapan ----
rows=[]
for p,item in d.get("paths",{}).items():
    if not isinstance(item,dict): continue
    for m,op in item.items():
        if m not in ("get","post","put","patch","delete"): continue
        key=f"{m.upper()} {p}"
        tag = op["tags"][0] if isinstance(op,dict) and op.get("tags") else "(none)"
        rows.append((tag,m.upper(),p,key))
miss_id=[k for _,_,_,k in rows if k not in ID]
miss_st=[k for _,_,_,k in rows if k not in ST]
assert not miss_id, f"ID hilang: {miss_id}"
assert not miss_st, f"ST hilang: {miss_st}"
print("Validasi spec OK — total operasi:",len(rows))

# ---- PIC per tag Jubelio (mengikuti §0b TASK-BREAKDOWN-JUBELIO.md) ----
PIC_BY_TAG = {
    "Authentication":"Darriel","Region":"Darriel","Location & The Rack Plan":"Darriel",
    "Product":"Darriel","Product Listing":"Darriel","Channels":"Darriel",
    "Journal":"Darriel","Cash & Bank":"Darriel","System Setting":"Darriel","Webhooks":"Darriel",
    "Inventory":"Rasyid","WMS (Warehouse Management System)":"Rasyid","Couriers":"Rasyid",
    "Sales":"Rasyid","Purchasing":"Rasyid","Contact":"Rasyid","Reports":"Rasyid",
}

import json
items=[]
for tag,m,p,key in rows:
    items.append({
        "domain":tag, "method":m, "endpoint":p,
        "function_id":ID[key], "status":ST[key],
        "baseline_status":ST[key], "pic":PIC_BY_TAG.get(tag),
        "priority":None, "source":"jubelio", "cilupbah_impl":None,
    })

# ---- 11 Epik (§18) ----
EPICS=[
 ("E1. Sales Invoice + Payment + Settlement","Sales","Rasyid","P0"),
 ("E2. Purchase Bill + Payment + Return + Settlement","Purchasing","Rasyid","P0"),
 ("E3. Contact terpadu (customers/suppliers)","Contact","Rasyid","P0"),
 ("E4. Finance: Journal + Accounts (CoA)","Journal","Darriel","P1"),
 ("E5. Cash & Bank","Cash & Bank","Darriel","P1"),
 ("E6. Tax lengkap","System Setting","Darriel","P1"),
 ("E7. Reports (PDF/print)","Reports","Rasyid","P1"),
 ("E8. Webhooks outbound","Webhooks","Darriel","P2"),
 ("E9. Marketplace: Shopee, Tokopedia, Lazada, Blibli","Channels","Darriel","P2"),
 ("E10. Inventory extended (promotions, price-list, bundles, dst)","Inventory","Rasyid","P3"),
 ("E11. System Setting (account-mapping, return-setting, dst)","System Setting","Darriel","P3"),
]
for name,dom,pic,prio in EPICS:
    items.append({"domain":"Epic","method":None,"endpoint":name,
        "function_id":f"Epik untuk domain {dom}","status":"todo","baseline_status":"todo",
        "pic":pic,"priority":prio,"source":"epic","cilupbah_impl":None})

# ---- 44 task Omnichannel (4 channel x 11 fitur) (§18d) ----
CHANNELS=["Shopee","Tokopedia","Lazada","Blibli"]
FEATURES=[
 "OAuth / Auth toko","Manajemen toko (list/refresh token)","Tarik order (pull)",
 "Terima/tolak order","Push produk (create listing)","Sync produk (update)",
 "Sync stok (push balik)","Sync harga","Webhook masuk","Cancel order","Logistik / kurir channel",
]
for ch in CHANNELS:
    for f in FEATURES:
        items.append({"domain":"Omnichannel","method":None,
            "endpoint":f"{ch} — {f}","function_id":f"Integrasi {f} untuk {ch} (pola TikTok)",
            "status":"todo","baseline_status":"todo","pic":"Darriel","priority":"P2",
            "source":"omnichannel","cilupbah_impl":None})

def norm(s): return "in_progress" if s=="partial" else s
def php(v):
    if v is None: return "null"
    return "'" + str(v).replace("\\","\\\\").replace("'","\\'") + "'"

lines=[]
for i in items:
    lines.append("            ["
        f"'domain'=>{php(i['domain'])},'method'=>{php(i['method'])},'endpoint'=>{php(i['endpoint'])},"
        f"'function_id'=>{php(i['function_id'])},'status'=>{php(norm(i['status']))},"
        f"'pic'=>{php(i['pic'])},'priority'=>{php(i['priority'])},'source'=>{php(i['source'])}],")
data_block="\n".join(lines)

seeder=f"""<?php

namespace Database\\Seeders;

use App\\Models\\TrackingItem;
use Illuminate\\Database\\Seeder;

/**
 * Data Dev Tracker (Jubelio + Epik + Omnichannel) — di-embed langsung di seeder.
 * Dibangkitkan otomatis dari `dist (2).yaml` via scripts/gen_tracking_json.py.
 * JANGAN edit manual; regenerate bila spec berubah. Total: {len(items)} item.
 *
 * Idempotent: item baru di-insert; status/notes/pic hasil edit user TIDAK ditimpa.
 */
class TrackingItemsSeeder extends Seeder
{{
    public function run(): void
    {{
        $created = 0;
        $updated = 0;

        foreach ($this->items() as $row) {{
            $item = TrackingItem::firstOrNew([
                'method' => $row['method'],
                'endpoint' => $row['endpoint'],
            ]);

            // Metadata selalu disinkronkan dari sumber dokumen.
            $item->domain = $row['domain'];
            $item->function_id = $row['function_id'];
            $item->baseline_status = $row['status'];
            $item->priority = $row['priority'];
            $item->source = $row['source'];

            if (! $item->exists) {{
                $item->status = $row['status'];
                $item->pic = $row['pic'];
                $created++;
            }} else {{
                $updated++; // JANGAN timpa status/notes/pic yang sudah diedit.
            }}

            $item->save();
        }}

        $this->command?->info("TrackingItems: +$created baru, $updated diperbarui (metadata).");
    }}

    /** @return array<int,array<string,?string>> */
    private function items(): array
    {{
        return [
{data_block}
        ];
    }}
}}
"""
open("database/seeders/TrackingItemsSeeder.php","w").write(seeder)
import collections
bysrc=collections.Counter(i["source"] for i in items)
byst=collections.Counter(norm(i["status"]) for i in items)
print("WROTE database/seeders/TrackingItemsSeeder.php")
print("  total:",len(items),"| by source:",dict(bysrc),"| by status:",dict(byst))
