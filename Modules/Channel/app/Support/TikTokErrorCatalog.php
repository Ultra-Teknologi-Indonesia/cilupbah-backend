<?php

namespace Modules\Channel\Support;

class TikTokErrorCatalog
{
    public const TOKEN = 'token';
    public const RETRYABLE = 'retryable';
    public const USER_FIXABLE = 'user_fixable';
    public const FATAL = 'fatal';

    /** Kode otorisasi/token yang memicu penyambungan ulang. */
    protected const TOKEN_CODES = [40100, 40102, 40103];

    protected const TOKEN_MESSAGE = 'Koneksi ke TikTok Shop terputus. Sistem akan menyambungkan ulang.';

    /** code => [kategori, pesan]. `:detail` diisi pesan asli dari TikTok. */
    protected const MAP = [
        // --- Gangguan sementara / batas permintaan (bisa dicoba ulang) ---
        '12001000' => [self::RETRYABLE, 'Terjadi kesalahan pada TikTok Shop. Coba lagi nanti.'],
        '12052881' => [self::RETRYABLE, 'Terjadi kesalahan pada TikTok Shop. Coba lagi nanti.'],
        '33001002' => [self::RETRYABLE, 'Terjadi kesalahan pada TikTok Shop. Coba lagi nanti.'],
        '36009002' => [self::RETRYABLE, 'Terlalu banyak permintaan dalam waktu singkat. Tunggu sebentar lalu coba lagi.'],
        '36009003' => [self::RETRYABLE, 'Terjadi kesalahan pada TikTok Shop. Coba lagi; jika terus terjadi hubungi TikTok Shop.'],
        '12052109' => [self::RETRYABLE, 'Terlalu banyak permintaan. Tunggu sebentar lalu coba lagi.'],
        '12052372' => [self::RETRYABLE, 'Gagal membuat gambar label energi (EU). Coba lagi.'],

        // --- Parameter umum ---
        '11050001' => [self::USER_FIXABLE, 'Data yang dikirim tidak sesuai.'],
        '36009004' => [self::USER_FIXABLE, 'Data yang dikirim tidak sesuai.'],
        '98001004' => [self::USER_FIXABLE, 'Data yang dikirim tidak sesuai: :detail'],
        '12052910' => [self::USER_FIXABLE, 'Data yang dikirim tidak sesuai: :detail'],

        // --- Gambar (upload / produk / deskripsi / variasi / kualifikasi) ---
        '12019116' => [self::USER_FIXABLE, 'Ukuran dimensi gambar melebihi batas yang diizinkan.'],
        '12038002' => [self::USER_FIXABLE, 'File yang diunggah tidak valid. Pastikan file gambar benar.'],
        '12038004' => [self::USER_FIXABLE, 'Gagal memproses gambar. Format atau isi gambar tidak didukung.'],
        '12038005' => [self::USER_FIXABLE, 'Ukuran file gambar melebihi batas maksimum.'],
        '12038014' => [self::USER_FIXABLE, 'Dimensi gambar melebihi batas yang diizinkan: :detail'],
        '12038015' => [self::USER_FIXABLE, 'Gagal memproses gambar. Format tidak didukung: :detail'],
        '12052300' => [self::USER_FIXABLE, 'Gambar utama produk tidak valid.'],
        '12052301' => [self::USER_FIXABLE, 'Lebar dan panjang gambar utama kurang dari batas minimum: :detail'],
        '12052302' => [self::USER_FIXABLE, 'Ukuran gambar utama melebihi batas.'],
        '12052304' => [self::USER_FIXABLE, 'Format gambar utama produk tidak didukung.'],
        '12052305' => [self::USER_FIXABLE, 'Rasio gambar utama produk melebihi batas.'],
        '12052306' => [self::USER_FIXABLE, 'Jumlah gambar utama produk melebihi batas.'],
        '12052324' => [self::USER_FIXABLE, 'Format file gambar deskripsi tidak didukung.'],
        '12052340' => [self::USER_FIXABLE, 'Gambar deskripsi produk tidak valid.'],
        '12052341' => [self::USER_FIXABLE, 'Ukuran gambar deskripsi melebihi batas.'],
        '12052342' => [self::USER_FIXABLE, 'Total ukuran gambar deskripsi melebihi batas.'],
        '12052343' => [self::USER_FIXABLE, 'Format gambar deskripsi tidak didukung.'],
        '12052520' => [self::USER_FIXABLE, 'Gambar variasi produk tidak valid.'],
        '12052521' => [self::USER_FIXABLE, 'Ukuran gambar variasi melebihi batas.'],
        '12052522' => [self::USER_FIXABLE, 'Gambar variasi produk wajib diisi.'],
        '12052523' => [self::USER_FIXABLE, 'Format gambar variasi tidak didukung.'],
        '12052524' => [self::USER_FIXABLE, 'Rasio gambar variasi tidak valid.'],
        '12052528' => [self::USER_FIXABLE, 'Gambar hanya bisa ditambahkan pada 1 variasi produk sebagai variasi utama.'],
        '12052650' => [self::USER_FIXABLE, 'Gambar dokumen kualifikasi tidak valid.'],
        '12052651' => [self::USER_FIXABLE, 'Ukuran gambar dokumen kualifikasi melebihi batas.'],
        '12052657' => [self::USER_FIXABLE, 'Gambar dan PDF dokumen kualifikasi melebihi batas.'],
        '12052658' => [self::USER_FIXABLE, 'File dokumen kualifikasi tidak valid.'],
        '12052670' => [self::USER_FIXABLE, 'Gambar tabel ukuran tidak valid. Gunakan gambar hasil unggahan resmi TikTok Shop.'],
        '12052671' => [self::USER_FIXABLE, 'Ukuran gambar tabel ukuran melebihi batas.'],
        '12052673' => [self::USER_FIXABLE, 'Gambar tabel ukuran wajib diisi.'],
        '12052722' => [self::USER_FIXABLE, 'Gambar tidak ditemukan di TikTok Shop.'],
        '12052356' => [self::USER_FIXABLE, 'Jumlah gambar tambahan SKU melebihi batas.'],
        '12052357' => [self::USER_FIXABLE, 'Gambar tambahan SKU hanya boleh diisi jika gambar SKU sudah ada.'],
        '12052360' => [self::USER_FIXABLE, 'Video produk tidak ditemukan.'],

        // --- Deskripsi & nama produk ---
        '12019006' => [self::USER_FIXABLE, 'Deskripsi produk tidak valid.'],
        '12052013' => [self::USER_FIXABLE, 'Deskripsi produk melebihi batas jumlah karakter.'],
        '12052015' => [self::USER_FIXABLE, 'Deskripsi produk wajib diisi.'],
        '12052056' => [self::USER_FIXABLE, 'Jumlah gambar di deskripsi melebihi batas.'],
        '12052344' => [self::USER_FIXABLE, 'Penulisan kode format deskripsi (HTML) salah.'],
        '12052345' => [self::USER_FIXABLE, 'Ada kode format deskripsi (HTML) yang tidak didukung.'],
        '12052346' => [self::USER_FIXABLE, 'Deskripsi produk tidak boleh mengandung karakter Mandarin.'],
        '12052348' => [self::USER_FIXABLE, 'Kode format deskripsi (HTML) kekurangan bagian yang wajib.'],
        '12052349' => [self::USER_FIXABLE, 'Kode format deskripsi (HTML) tidak boleh disusun bertingkat.'],
        '12052350' => [self::USER_FIXABLE, 'Kode format deskripsi (HTML) mengandung bagian yang tidak diperbolehkan.'],
        '12052352' => [self::USER_FIXABLE, 'Susunan bertingkat pada deskripsi melebihi batas.'],
        '12052051' => [self::USER_FIXABLE, 'Nama produk melebihi batas karakter.'],
        '12052261' => [self::USER_FIXABLE, 'Nama produk tidak boleh kosong.'],
        '12052262' => [self::USER_FIXABLE, 'Nama produk tidak boleh mengandung karakter Mandarin.'],
        '12052263' => [self::USER_FIXABLE, 'Awalan nama produk tidak diperbolehkan.'],
        '12052931' => [self::USER_FIXABLE, 'Nama produk melanggar aturan format (tanpa emoji, karakter kontrol, hanya simbol, atau karakter berulang berlebihan).'],
        '12052932' => [self::USER_FIXABLE, 'Deskripsi melanggar aturan format (tanpa emoji, karakter kontrol, hanya simbol, atau karakter berulang berlebihan).'],
        '12052933' => [self::USER_FIXABLE, 'Nama variasi melanggar aturan format (tanpa emoji, karakter kontrol, hanya simbol, atau karakter berulang berlebihan).'],
        '12052934' => [self::USER_FIXABLE, 'Nilai variasi melanggar aturan format (tanpa emoji, karakter kontrol, hanya simbol, atau karakter berulang berlebihan).'],
        '12052935' => [self::USER_FIXABLE, 'Nilai atribut melanggar aturan format (tanpa emoji, karakter kontrol, hanya simbol, atau karakter berulang berlebihan).'],

        // --- Paket & dimensi ---
        '12019011' => [self::USER_FIXABLE, 'Berat paket produk tidak valid.'],
        '12019012' => [self::USER_FIXABLE, 'Ukuran paket produk tidak valid.'],
        '12019061' => [self::USER_FIXABLE, 'Satuan berat/dimensi imperial tidak didukung untuk produk lokal di luar AS.'],
        '12052003' => [self::USER_FIXABLE, 'Format tinggi paket tidak sesuai.'],
        '12052004' => [self::USER_FIXABLE, 'Format lebar paket tidak sesuai.'],
        '12052005' => [self::USER_FIXABLE, 'Format panjang paket tidak sesuai.'],
        '12052006' => [self::USER_FIXABLE, 'Format berat paket tidak sesuai.'],
        '12052116' => [self::USER_FIXABLE, 'Ukuran paket produk tidak valid.'],
        '12052181' => [self::USER_FIXABLE, 'Berat paket produk tidak boleh 0.'],
        '12052915' => [self::USER_FIXABLE, 'Satuan berat dan satuan dimensi tidak cocok.'],

        // --- Kategori ---
        '12052002' => [self::USER_FIXABLE, 'Format kategori tidak sesuai.'],
        '12052023' => [self::USER_FIXABLE, 'Kategori tidak ditemukan.'],
        '12052024' => [self::USER_FIXABLE, 'Kategori belum sampai level akhir (subkategori terkecil).'],
        '12052025' => [self::USER_FIXABLE, 'Kategori tidak valid.'],
        '12052217' => [self::USER_FIXABLE, 'Semua toko wilayah harus memakai kategori versi 2.'],
        '12052230' => [self::USER_FIXABLE, 'Versi kategori dan ID kategori tidak cocok.'],
        '12052446' => [self::FATAL, 'Kategori belum tersedia di pasar ini.'],
        '12052158' => [self::FATAL, 'Kategori tidak bisa diubah.'],
        '12052220' => [self::FATAL, 'Kategori ini dilarang atau tidak didukung TikTok Shop. Pilih kategori lain.'],
        '12052221' => [self::FATAL, 'Hanya penjual terpilih yang boleh berjualan di kategori ini.'],
        '12052222' => [self::USER_FIXABLE, 'Kategori ini tidak mendukung COD (bayar di tempat).'],
        '12052223' => [self::FATAL, 'Kategori ini dibatasi. Ajukan izin lewat Qualification Center di Seller Center.'],
        '12052225' => [self::FATAL, 'Anda belum berwenang menjual di kategori ini karena bukan kategori utama toko Anda. Hubungi account manager atau pilih kategori lain.'],
        '12052226' => [self::FATAL, 'Kategori ini dibatasi. Ajukan izin lewat Qualification Center di Seller Center.'],
        '12052227' => [self::FATAL, 'Kategori tidak diizinkan atau tidak tersedia.'],

        // --- Merek ---
        '12019013' => [self::USER_FIXABLE, 'Merek produk tidak valid.'],
        '12052026' => [self::USER_FIXABLE, 'Merek tidak ditemukan.'],
        '12052200' => [self::USER_FIXABLE, 'Merek sudah kedaluwarsa.'],
        '12052201' => [self::FATAL, 'Anda belum berwenang menjual merek ini di kategori ini. Ajukan izin lewat Qualification Center di Seller Center.'],
        '12052207' => [self::FATAL, 'Merek ini tidak mendukung gudang tersebut.'],
        '12052208' => [self::FATAL, 'Perlu otorisasi merek untuk menerbitkan produk ini.'],

        // --- Atribut & variasi ---
        '12019019' => [self::USER_FIXABLE, 'Variasi produk tidak valid.'],
        '12052104' => [self::USER_FIXABLE, 'Atribut produk wajib diisi.'],
        '12052151' => [self::USER_FIXABLE, 'Nilai atribut mengandung kata yang dilarang.'],
        '12052152' => [self::USER_FIXABLE, 'Nama variasi mengandung kata yang dilarang.'],
        '12052153' => [self::USER_FIXABLE, 'Nilai variasi mengandung kata yang dilarang.'],
        '12052172' => [self::USER_FIXABLE, 'Jenis penjualan tidak valid.'],
        '12052182' => [self::USER_FIXABLE, 'Ada kolom variasi yang wajib diisi mengikuti pilihan Anda: :detail'],
        '12052183' => [self::USER_FIXABLE, 'Ada kolom variasi yang harus dikosongkan mengikuti pilihan Anda: :detail'],
        '12052240' => [self::USER_FIXABLE, 'Atribut khusus (custom) tidak didukung.'],
        '12052241' => [self::USER_FIXABLE, 'Nama atau ID atribut tidak boleh kosong.'],
        '12052242' => [self::USER_FIXABLE, 'Nama atribut melebihi batas karakter.'],
        '12052243' => [self::USER_FIXABLE, 'Nama atribut/variasi tidak boleh mengandung karakter Mandarin.'],
        '12052244' => [self::USER_FIXABLE, 'Nama atribut ganda.'],
        '12052245' => [self::USER_FIXABLE, 'Atribut produk mengandung karakter yang tidak diperbolehkan. Perbaiki lalu kirim ulang.'],
        '12052246' => [self::USER_FIXABLE, 'Atribut ini tidak mendukung pilihan ganda: :detail'],
        '12052247' => [self::USER_FIXABLE, 'Atribut produk khusus (custom) tidak didukung.'],
        '12052248' => [self::USER_FIXABLE, 'Nilai atribut tidak boleh kosong: :detail'],
        '12052249' => [self::USER_FIXABLE, 'Nilai atribut melebihi batas karakter: :detail'],
        '12052250' => [self::USER_FIXABLE, 'Nilai atribut tidak boleh mengandung karakter Mandarin: :detail'],
        '12052251' => [self::USER_FIXABLE, 'Ada nilai atribut yang ganda. Hapus atau ubah nilai yang ganda.'],
        '12052253' => [self::USER_FIXABLE, 'ID nilai atribut ganda.'],
        '12052254' => [self::USER_FIXABLE, 'ID atribut ganda.'],
        '12052256' => [self::USER_FIXABLE, 'Nilai atribut harus berupa angka positif: :detail'],
        '12052525' => [self::USER_FIXABLE, 'Jumlah variasi tidak boleh lebih dari 3.'],
        '12052526' => [self::USER_FIXABLE, 'Jumlah nilai variasi melebihi batas.'],
        '12052527' => [self::USER_FIXABLE, 'ID variasi tidak ditemukan.'],
        '12052529' => [self::USER_FIXABLE, 'ID nilai atribut tidak ditemukan.'],
        '12052550' => [self::USER_FIXABLE, 'Setiap SKU harus memuat semua variasi.'],
        '12052560' => [self::USER_FIXABLE, 'SKU memuat variasi yang ganda.'],
        '12052159' => [self::USER_FIXABLE, 'Untuk produk satuan unik, total jumlah harus 1.'],
        '12052160' => [self::USER_FIXABLE, 'Produk ini harus punya 1 SKU tanpa variasi.'],
        '12052162' => [self::USER_FIXABLE, 'Produk satuan unik harus punya 1 SKU tanpa variasi.'],

        // --- SKU & gudang ---
        '12019022' => [self::USER_FIXABLE, 'Setiap SKU harus memiliki gudang yang valid.'],
        '12019056' => [self::USER_FIXABLE, 'SKU tidak boleh ditambahkan.'],
        '12019057' => [self::USER_FIXABLE, 'SKU tidak boleh dihapus.'],
        '12019059' => [self::USER_FIXABLE, 'SKU penjual tidak valid.'],
        '12052050' => [self::USER_FIXABLE, 'Satu produk tidak boleh lebih dari 100 SKU.'],
        '12052054' => [self::USER_FIXABLE, 'Teks SKU penjual melebihi batas karakter.'],
        '12052055' => [self::USER_FIXABLE, 'Jumlah stok SKU per gudang di luar batas yang diizinkan.'],
        '12052362' => [self::USER_FIXABLE, 'Jumlah satuan SKU wajib diisi.'],
        '12052361' => [self::USER_FIXABLE, 'Nilai satuan dasar tidak didukung. Ambil nilai yang didukung dari data atribut TikTok.'],
        '12052553' => [self::USER_FIXABLE, 'ID SKU ganda.'],
        '12052554' => [self::USER_FIXABLE, 'Nama SKU tidak boleh mengandung karakter Mandarin.'],
        '12052555' => [self::USER_FIXABLE, 'ID gudang ganda.'],
        '12052557' => [self::USER_FIXABLE, 'ID SKU tidak termasuk dalam produk ini.'],
        '12052558' => [self::USER_FIXABLE, 'Jumlah SKU produk melebihi batas.'],
        '12052559' => [self::USER_FIXABLE, 'Produk memiliki SKU default ganda.'],
        '12052902' => [self::USER_FIXABLE, 'Daftar SKU kosong. Kosongkan sepenuhnya atau lengkapi semua detail.'],
        '12052094' => [self::FATAL, 'Toko Anda belum memiliki izin banyak gudang.'],
        '12052095' => [self::USER_FIXABLE, 'Data gudang tidak bisa diproses.'],
        '12052096' => [self::USER_FIXABLE, 'Tidak bisa memperbarui harga SKU tanpa gudang. Tetapkan gudang lalu coba lagi.'],
        '12052097' => [self::USER_FIXABLE, 'Gudang tidak ditemukan.'],
        '12052115' => [self::USER_FIXABLE, 'Toko Anda belum memiliki gudang. Tambahkan gudang terlebih dahulu.'],
        '12052141' => [self::USER_FIXABLE, 'Ubah stok dari pengaturan global.'],
        '12052144' => [self::FATAL, 'Gudang platform tidak bisa diedit.'],
        '12052364' => [self::USER_FIXABLE, 'Gudang tidak tersedia karena produk tidak bisa dikirim ke pasar tujuan dari gudang ini.'],
        '12052403' => [self::USER_FIXABLE, 'Gudang tidak mendukung pemenuhan untuk toko ini.'],
        '12052404' => [self::USER_FIXABLE, 'Banyak gudang tidak mendukung layanan logistik kustom.'],
        '12052405' => [self::USER_FIXABLE, 'Gudang belum mengaktifkan layanan logistik yang dilanggan.'],
        '12052420' => [self::USER_FIXABLE, 'Gudang belum mengatur layanan logistik.'],
        '12052465' => [self::USER_FIXABLE, 'Gudang tidak tersedia untuk produk yang terkena aturan keamanan produk Uni Eropa (GPR).'],
        '12052530' => [self::USER_FIXABLE, 'Gudang tidak terdaftar pada toko Anda: :detail'],
        '12052531' => [self::USER_FIXABLE, 'Gudang sedang dinonaktifkan.'],
        '12052532' => [self::USER_FIXABLE, 'Gudang tersebut bukan gudang pengiriman.'],
        '12052533' => [self::USER_FIXABLE, 'Menghapus, menambah, atau mengganti gudang tidak diperbolehkan. Gunakan gudang asli SKU.'],
        '12052534' => [self::USER_FIXABLE, 'Gudang tidak bisa dihapus.'],
        '12052535' => [self::USER_FIXABLE, 'Tetapkan gudang retur di Seller Center dulu sebelum menerbitkan produk.'],
        '12052549' => [self::USER_FIXABLE, 'Template pengiriman saat ini tidak tersedia karena metode pengiriman tidak tersedia. Ganti template pengiriman.'],
        '12052402' => [self::USER_FIXABLE, 'Template pengiriman toko masih kosong.'],
        '12052037' => [self::USER_FIXABLE, 'Stok tidak bisa diperbarui. Pastikan gudang benar dan sertakan semua gudang yang menyimpan produk; stok yang dialokasikan otomatis tidak bisa diubah manual.'],
        '12052503' => [self::USER_FIXABLE, 'Jika stok diatur berbagi antar SKU, jumlah stok harus sama di semua SKU.'],
        '12052516' => [self::USER_FIXABLE, 'Detail stok belum lengkap. Sertakan semua gudang SKU beserta jumlahnya.'],

        // --- Harga ---
        '12019045' => [self::FATAL, 'Harga terkunci.'],
        '12052012' => [self::USER_FIXABLE, 'Format harga tidak sesuai.'],
        '12052073' => [self::USER_FIXABLE, 'Harga produk tidak valid.'],
        '12052084' => [self::USER_FIXABLE, 'Mata uang tidak tersedia untuk toko/wilayah ini.'],
        '12052092' => [self::USER_FIXABLE, 'Harga jual produk tidak valid.'],
        '12052375' => [self::USER_FIXABLE, 'Harga coret (harga sebelum diskon) tidak valid.'],
        '12052376' => [self::USER_FIXABLE, 'Harga coret (harga sebelum diskon) tidak didukung.'],
        '12052383' => [self::USER_FIXABLE, 'Harga coret (harga sebelum diskon) melebihi batas.'],
        '12052424' => [self::USER_FIXABLE, 'Harga jual wajib diisi.'],
        '12052570' => [self::USER_FIXABLE, 'Harga produk melebihi batas.'],
        '12052571' => [self::USER_FIXABLE, 'Ada SKU yang belum diisi harganya.'],
        '12052572' => [self::USER_FIXABLE, 'Harga jual tidak valid.'],
        '12052574' => [self::USER_FIXABLE, 'Konfigurasi harga satuan tidak valid.'],
        '12052038' => [self::FATAL, 'Harga terkunci karena produk sedang promosi.'],
        '12052393' => [self::FATAL, 'Produk terkunci karena promosi; stok tidak bisa diturunkan di bawah stok saat ini.'],

        // --- Pre-order / made-to-order / backorder ---
        '12019095' => [self::USER_FIXABLE, 'Jenis pemenuhan pre-order wajib diisi.'],
        '12019096' => [self::USER_FIXABLE, 'Jenis pre-order wajib diisi.'],
        '12019097' => [self::USER_FIXABLE, 'Tanggal rilis pre-order wajib diisi.'],
        '12019098' => [self::USER_FIXABLE, 'Durasi proses pre-order wajib diisi.'],
        '12019099' => [self::FATAL, 'Jenis pre-order tidak didukung di wilayah Anda.'],
        '12019100' => [self::USER_FIXABLE, 'Semua SKU produk harus memakai jenis pre-order yang sama.'],
        '12052268' => [self::FATAL, 'Toko Anda belum mendukung pre-sale.'],
        '12052269' => [self::USER_FIXABLE, 'Waktu yang dipilih di luar batas yang diizinkan.'],
        '12052273' => [self::FATAL, 'Setelah produk pre-order tayang, info pre-order tidak bisa diubah.'],
        '12052274' => [self::USER_FIXABLE, 'Mode pre-sale dan pre-order tidak bisa digunakan bersamaan.'],
        '12052275' => [self::USER_FIXABLE, 'Jumlah hari produksi (dibuat sesuai pesanan) SKU tidak valid.'],
        '12052278' => [self::FATAL, 'Toko Anda belum mendukung produk dibuat sesuai pesanan (made-to-order).'],
        '12052314' => [self::FATAL, 'Produk standar tidak bisa diubah menjadi produk dibuat sesuai pesanan.'],
        '12052315' => [self::FATAL, 'Produk standar tidak bisa diubah menjadi produk pre-order.'],
        '12052329' => [self::FATAL, 'Produk pre-order tidak bisa diubah menjadi produk biasa.'],
        '12052702' => [self::FATAL, 'Toko Anda belum mendukung pre-order.'],
        '12052712' => [self::USER_FIXABLE, 'Pengaturan pre-order SKU tidak valid.'],
        '12052309' => [self::FATAL, 'Toko Anda belum memiliki izin stok indent (backorder).'],
        '12052310' => [self::USER_FIXABLE, 'Informasi stok indent (backorder) belum memenuhi ketentuan.'],
        '12052311' => [self::USER_FIXABLE, 'Jumlah atau proporsi produk stok indent (backorder) melebihi batas.'],
        '12052313' => [self::USER_FIXABLE, 'Produk ini tidak mengizinkan stok indent (backorder).'],

        // --- Produsen / penanggung jawab / kode identifikasi ---
        '12052282' => [self::USER_FIXABLE, 'Produsen produk wajib diisi.'],
        '12052283' => [self::USER_FIXABLE, 'Jumlah produsen melebihi batas yang diizinkan.'],
        '12052284' => [self::USER_FIXABLE, 'Produsen dengan ID tersebut tidak ditemukan: :detail'],
        '12052285' => [self::USER_FIXABLE, 'Produsen tersebut tidak terhubung dengan toko Anda: :detail'],
        '12052287' => [self::USER_FIXABLE, 'Penanggung jawab produk wajib diisi.'],
        '12052288' => [self::USER_FIXABLE, 'Jumlah penanggung jawab di luar batas yang diizinkan: :detail'],
        '12052289' => [self::USER_FIXABLE, 'Penanggung jawab dengan ID tersebut tidak ditemukan: :detail'],
        '12052290' => [self::USER_FIXABLE, 'Penanggung jawab tersebut tidak terhubung dengan toko Anda: :detail'],
        '12052291' => [self::FATAL, 'Kode identifikasi tambahan tidak didukung di wilayah ini.'],
        '12052293' => [self::USER_FIXABLE, 'Kode identifikasi tambahan tidak boleh diisi jika kode identifikasi utama kosong.'],
        '12052294' => [self::USER_FIXABLE, 'Format kode identifikasi tambahan tidak sesuai.'],
        '12052295' => [self::USER_FIXABLE, 'Ada ID produsen yang ganda pada produk yang sama.'],
        '12052296' => [self::USER_FIXABLE, 'Ada ID penanggung jawab yang ganda pada produk yang sama.'],
        '12052365' => [self::USER_FIXABLE, 'Produsen belum punya versi bahasa untuk pasar ini.'],
        '12052366' => [self::USER_FIXABLE, 'Penanggung jawab belum punya versi bahasa untuk pasar ini.'],
        '12052591' => [self::USER_FIXABLE, 'Jumlah digit kode identifikasi tidak valid.'],
        '12052592' => [self::USER_FIXABLE, 'Kode identifikasi sudah dipakai, tidak bisa dimasukkan dua kali.'],
        '12052593' => [self::FATAL, 'Kode identifikasi tidak bisa diubah setelah dikirim.'],
        '12052598' => [self::USER_FIXABLE, 'Hanya digit terakhir yang boleh berupa huruf X.'],
        '12052600' => [self::USER_FIXABLE, 'Jenis kode identifikasi wajib dipilih.'],

        // --- Kualifikasi / sertifikat / url / kontak ---
        '12052105' => [self::USER_FIXABLE, 'Dokumen kualifikasi yang wajib belum lengkap.'],
        '12052128' => [self::USER_FIXABLE, 'Tabel ukuran tidak ditemukan.'],
        '12052385' => [self::USER_FIXABLE, 'Masukkan tanggal kedaluwarsa sertifikat yang valid.'],
        '12052655' => [self::USER_FIXABLE, 'ID dokumen kualifikasi tidak ditemukan.'],
        '12052656' => [self::USER_FIXABLE, 'ID dokumen kualifikasi ganda.'],
        '12052235' => [self::USER_FIXABLE, 'Format URL tidak valid.'],
        '12052236' => [self::USER_FIXABLE, 'Protokol URL tidak aman.'],
        '12052237' => [self::USER_FIXABLE, 'Alamat URL tidak aman.'],
        '12052238' => [self::USER_FIXABLE, 'URL melebihi 200 karakter.'],
        '12052923' => [self::USER_FIXABLE, 'Informasi kontak wajib diisi.'],
        '12052369' => [self::USER_FIXABLE, 'Panjang kode referensi produk (external_id) tidak sesuai.'],
        '12052996' => [self::USER_FIXABLE, 'Kode referensi produk (external_id) sudah dipakai (ganda).'],

        // --- Pfand (deposit kemasan) ---
        '12052495' => [self::USER_FIXABLE, 'Jenis kemasan Pfand wajib diisi.'],
        '12052497' => [self::USER_FIXABLE, 'Kolom Pfand tidak didukung di negara ini.'],
        '12052498' => [self::USER_FIXABLE, 'Nominal Pfand tidak boleh diisi.'],
        '12052543' => [self::USER_FIXABLE, 'Produk dengan Pfand tidak didukung dalam pembuatan Paket Virtual.'],

        // --- Deposit / batas jual / minimum ---
        '12019215' => [self::USER_FIXABLE, 'Saldo deposit tidak cukup.'],
        '12052585' => [self::USER_FIXABLE, 'Saldo deposit tidak cukup.'],
        '12052093' => [self::USER_FIXABLE, 'Jumlah produk sudah mencapai batas maksimum penjual.'],
        '12052706' => [self::USER_FIXABLE, 'Jumlah penjualan minimum melebihi batas.'],
        '12052707' => [self::USER_FIXABLE, 'Pengaturan jumlah penjualan minimum tidak didukung.'],
        '12052317' => [self::USER_FIXABLE, 'Jumlah pemesanan tidak didukung.'],
        '12052319' => [self::USER_FIXABLE, 'Jumlah pesanan maksimum tidak boleh lebih kecil dari minimum.'],

        // --- Penjadwalan / status / platform / handling time ---
        '12019113' => [self::USER_FIXABLE, 'Status produk tidak valid.'],
        '12019210' => [self::USER_FIXABLE, 'Data publikasi tidak valid.'],
        '12052330' => [self::USER_FIXABLE, 'Produk tidak didukung untuk tampil di platform penjualan yang dipilih.'],
        '12052358' => [self::USER_FIXABLE, 'Platform Tokopedia tidak mendukung jenis produk ini.'],
        '12052359' => [self::USER_FIXABLE, 'Fitur simpan langganan tidak didukung.'],
        '12052316' => [self::USER_FIXABLE, 'Produk bekas tidak berlaku di platform penjualan ini.'],
        '12052490' => [self::USER_FIXABLE, 'Waktu proses pesanan harus dalam batas yang diizinkan.'],
        '12052488' => [self::USER_FIXABLE, 'Wilayah ini tidak mendukung simpan draf setelah produk dikirim.'],
        '12052603' => [self::USER_FIXABLE, 'Penjadwalan tayang tidak didukung.'],
        '12052604' => [self::USER_FIXABLE, 'Waktu penjadwalan tayang tidak valid.'],
        '12052219' => [self::USER_FIXABLE, 'Produk instan tidak mendukung status "tidak dijual".'],
        '12052228' => [self::USER_FIXABLE, 'Produk paket tidak mendukung status "tidak dijual".'],
        '12052229' => [self::USER_FIXABLE, 'Produk pre-order tidak mendukung status "tidak dijual".'],
        '12052845' => [self::USER_FIXABLE, 'Tidak mendukung produk berstatus non-aktif.'],
        '12052922' => [self::USER_FIXABLE, 'Ada batasan kolom yang bisa diedit.'],
        '12052990' => [self::USER_FIXABLE, 'Produk tidak lolos pemeriksaan.'],

        // --- Batas jumlah pada pencarian / operasi massal ---
        '12019027' => [self::USER_FIXABLE, 'Maksimal 10 SKU penjual pada filter pencarian.'],
        '12019087' => [self::USER_FIXABLE, 'Jumlah SKU melebihi batas 10.'],
        '12019108' => [self::USER_FIXABLE, 'Nomor halaman tidak valid.'],
        '12019109' => [self::USER_FIXABLE, 'Ukuran halaman tidak valid.'],
        '12019118' => [self::USER_FIXABLE, 'Rentang waktu pembuatan pada pencarian tidak valid.'],
        '12019119' => [self::USER_FIXABLE, 'Rentang waktu pembaruan pada pencarian tidak valid.'],
        '12019120' => [self::USER_FIXABLE, 'Jumlah produk melebihi batas.'],
        '12052180' => [self::USER_FIXABLE, 'Hasil pencarian melebihi 10.000. Persempit filter pencarian.'],

        // --- Combo / bundle ---
        '12052805' => [self::USER_FIXABLE, 'Paket (bundle) tidak mendukung penambahan jenis produk ini.'],
        '12052815' => [self::USER_FIXABLE, 'Produk dalam bundle deal tidak bisa mengatur jumlah penjualan minimum.'],
        '12052830' => [self::USER_FIXABLE, 'Jumlah sub-produk per paket melebihi batas: :detail'],
        '12052831' => [self::USER_FIXABLE, 'Jumlah sub-SKU per SKU paket melebihi batas: :detail'],
        '12052832' => [self::USER_FIXABLE, 'Koefisien sub-SKU per SKU paket di luar batas: :detail'],
        '12052833' => [self::USER_FIXABLE, 'Jumlah paket per produk melebihi batas.'],
        '12052834' => [self::USER_FIXABLE, 'Jumlah SKU paket per SKU melebihi batas.'],
        '12052835' => [self::USER_FIXABLE, 'Sub-produk tidak ditemukan.'],
        '12052836' => [self::USER_FIXABLE, 'Kategori paket tidak sama dengan kategori sub-produk utama.'],
        '12052837' => [self::USER_FIXABLE, 'Kategori paket tidak termasuk dalam kategori yang diizinkan.'],
        '12052838' => [self::USER_FIXABLE, 'Kategori tingkat pertama sub-produk dalam paket tidak konsisten.'],
        '12052840' => [self::USER_FIXABLE, 'Sub-SKU dalam satu SKU paket tidak boleh ganda.'],
        '12052841' => [self::USER_FIXABLE, 'Hubungan sub-SKU antar SKU paket tidak boleh ganda.'],
        '12052842' => [self::USER_FIXABLE, 'Paket tidak memuat sub-produk utama.'],
        '12052843' => [self::FATAL, 'Sub-produk utama dalam paket tidak bisa diubah.'],
        '12052844' => [self::USER_FIXABLE, 'Sub-SKU tidak ada dalam sub-produk.'],
        '12052846' => [self::USER_FIXABLE, 'Paket (combo) tidak mendukung penambahan jenis produk ini.'],
        '12052847' => [self::FATAL, 'Hubungan SKU paket tidak bisa diubah.'],
        '12052848' => [self::USER_FIXABLE, 'Paket (combo) tidak bisa mengatur jumlah penjualan minimum.'],
        '12052849' => [self::FATAL, 'Anda belum punya izin mengelola produk paket (combo).'],
        '12052850' => [self::FATAL, 'Stok produk paket (combo) tidak bisa diedit.'],
        '12052708' => [self::FATAL, 'Stok produk paket (combo) tidak bisa diedit.'],
        '12052851' => [self::USER_FIXABLE, 'Gudang SKU paket masih kosong.'],
        '12052853' => [self::USER_FIXABLE, 'Variasi produk paket tidak cocok.'],
        '12052854' => [self::USER_FIXABLE, 'Variasi produk paket harus unik.'],
        '12052855' => [self::USER_FIXABLE, 'Gudang produk paket tidak cocok.'],
        '12052856' => [self::USER_FIXABLE, 'Produk paket tidak mendukung layanan logistik kustom.'],
        '12052857' => [self::FATAL, 'Produk paket dan produk biasa tidak bisa saling dikonversi.'],
        '12052858' => [self::USER_FIXABLE, 'SKU produk paket tidak bisa dihubungkan dengan SKU produk paket lain.'],
        '12052859' => [self::USER_FIXABLE, 'Jika SKU paket hanya terkait 1 sub-SKU, koefisiennya minimal 2.'],
        '12052861' => [self::USER_FIXABLE, 'Harga SKU paket tidak boleh melebihi total harga sub-SKU-nya.'],
        '12052862' => [self::USER_FIXABLE, 'Merek produk paket harus termasuk dalam merek sub-produk.'],
        '12052863' => [self::USER_FIXABLE, 'Semua SKU produk paket harus berupa SKU paket.'],
        '12052864' => [self::USER_FIXABLE, 'Tidak bisa menambahkan SKU nonaktif ke produk paket.'],

        // --- Produk replika (multi-market) ---
        '12052508' => [self::USER_FIXABLE, 'ID SKU pada produk replika tidak valid atau tidak ditemukan.'],
        '12052509' => [self::USER_FIXABLE, 'SKU penjual wajib diisi untuk semua SKU baru pada produk replika.'],
        '12052510' => [self::USER_FIXABLE, 'SKU penjual tidak cocok dengan produk sumber. Pastikan sama di kedua sisi.'],
        '12052511' => [self::USER_FIXABLE, 'Detail SKU pada produk replika belum lengkap.'],
        '12052512' => [self::USER_FIXABLE, 'Belum ada replika produk ini. Buat replika produk terlebih dahulu.'],
        '12052513' => [self::USER_FIXABLE, 'Belum ada replika produk ini di wilayah tersebut. Buat replika terlebih dahulu.'],
        '12052514' => [self::USER_FIXABLE, 'Kategori berubah, sinkronkan produk ke semua pasar lain yang punya replika.'],
        '12052515' => [self::USER_FIXABLE, 'Variasi berubah, sinkronkan produk ke semua pasar lain yang punya replika.'],

        // --- Tidak bisa dilanjutkan: produk / toko / status ---
        '12019150' => [self::FATAL, 'Produk tidak ditemukan.'],
        '12052032' => [self::FATAL, 'Produk tidak ditemukan.'],
        '12052260' => [self::FATAL, 'Produk tidak ditemukan.'],
        '12052034' => [self::FATAL, 'Produk ini bukan milik toko Anda.'],
        '12052048' => [self::FATAL, 'Produk tidak bisa diubah karena tidak terdaftar di toko atau akun Anda.'],
        '12052163' => [self::FATAL, 'Produk hadiah tidak bisa diedit.'],
        '12052992' => [self::FATAL, 'Anda belum punya izin membuat produk hadiah.'],
        '12052307' => [self::FATAL, 'SKU dalam undian tidak bisa dihapus.'],
        '12052308' => [self::FATAL, 'Ada banding (appeal) yang sedang berjalan. Batalkan banding dulu untuk mengubah produk.'],
        '12052371' => [self::FATAL, 'Produk lelang sedang dalam penawaran, info produk tidak bisa diubah.'],
        '12052377' => [self::FATAL, 'Produk memiliki stok Dilayani Tokopedia, tidak bisa dihapus.'],
        '12052382' => [self::FATAL, 'Jumlah stok gudang Dilayani Tokopedia tidak bisa diubah.'],
        '12052332' => [self::FATAL, 'Seller Center sedang terkunci selama integrasi toko; hanya stok dan harga yang bisa diubah.'],
        '12052419' => [self::FATAL, 'Ada tugas toko yang wajib diselesaikan sebelum memasang produk. Selesaikan dulu.'],
        '12052547' => [self::FATAL, 'Tidak ada versi produk yang sedang ditinjau. Ambil versi yang sedang tayang.'],
        '12052901' => [self::FATAL, 'Produk pada status saat ini tidak bisa dikenai tindakan ini (misalnya sedang ditinjau). Ubah status produk lalu coba lagi.'],
        '12052921' => [self::FATAL, 'Stok tidak bisa diubah karena produk berbagi stok dengan toko sumber.'],
        '12052700' => [self::FATAL, 'Toko/penjual sedang tidak aktif.'],
        '12052701' => [self::FATAL, 'Penjual lintas negara tidak bisa membuat produk lokal secara langsung.'],
        '12052703' => [self::USER_FIXABLE, 'Nomor pajak penjual tidak valid.'],
        '12052704' => [self::FATAL, 'Penjual tidak ditemukan.'],
        '12052705' => [self::FATAL, 'Penjual tidak bisa mengelola produk lokal.'],
        '12052994' => [self::FATAL, 'Penjual bukan penjual Tokopedia.'],
        '12052995' => [self::FATAL, 'Penjual belum menyelesaikan pendaftaran ke TikTok Shop.'],
    ];

    public static function resolve(int|string $rawCode, ?string $rawMessage = null): array
    {
        $code = trim((string) $rawCode);
        $message = trim((string) $rawMessage);
        $codeInt = is_numeric($code) ? (int) $code : null;

        if ($codeInt !== null && in_array($codeInt, self::TOKEN_CODES, true)) {
            return self::pack($code, self::TOKEN, self::TOKEN_MESSAGE, $message);
        }

        if (isset(self::MAP[$code])) {
            [$category, $template] = self::MAP[$code];

            return self::pack($code, $category, self::fill($template, $message), $message);
        }

        $fallback = $message !== ''
            ? 'Permintaan ditolak TikTok Shop: ' . $message
            : 'Permintaan ditolak TikTok Shop. Coba lagi atau hubungi TikTok Shop.';

        return self::pack($code, self::FATAL, $fallback, $message);
    }

    protected static function pack(string $code, string $category, string $message, string $rawMessage): array
    {
        return [
            'code' => $code,
            'category' => $category,
            'message' => $message,
            'raw_message' => $rawMessage !== '' ? $rawMessage : null,
        ];
    }

    protected static function fill(string $template, string $detail): string
    {
        if (! str_contains($template, ':detail')) {
            return $template;
        }

        $replacement = $detail !== '' ? $detail : 'tidak ada keterangan tambahan';

        return trim(str_replace(':detail', $replacement, $template));
    }
}
