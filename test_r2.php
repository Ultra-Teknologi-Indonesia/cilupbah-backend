<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Storage;

try {
    echo "1. Mempersiapkan file contoh (lokal)...\n";
    $imageContent = "Ini adalah file teks percobaan untuk upload ke Cloudflare R2! Waktu: " . date('Y-m-d H:i:s');
    
    $filename = 'test-folder/percobaan-upload-' . time() . '.txt';
    $disk = Storage::disk('s3');

    echo "2. Mengunggah ke Cloudflare R2 (S3)...\n";
    // Gunakan public visibility jika bucket diatur public
    $uploaded = $disk->put($filename, $imageContent, 'public');

    if ($uploaded) {
        echo "   -> Berhasil diunggah!\n";
        
        echo "3. Mencoba mengambil file (Get)...\n";
        $retrieved = $disk->get($filename);
        if ($retrieved) {
            echo "   -> File berhasil diambil (Ukuran: " . strlen($retrieved) . " bytes)\n";
        }

        echo "4. Mendapatkan URL Publik...\n";
        $url = $disk->url($filename);
        echo "   -> URL File: " . $url . "\n";

        // Optional: Kita tidak menghapus filenya agar user bisa melihat langsung di browser
        echo "\nUji coba selesai dengan SUKSES! Anda bisa mengklik URL di atas untuk memastikan gambar bisa diakses publik.\n";
    } else {
        echo "   -> GAGAL mengunggah file!\n";
    }
} catch (\Exception $e) {
    echo "Terjadi Error: " . $e->getMessage() . "\n";
}
