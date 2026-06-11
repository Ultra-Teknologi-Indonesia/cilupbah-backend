<?php

namespace App\Models;

use App\Concerns\HasUploadableMedia;
use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;

/**
 * Pemilik generik untuk file yang diunggah lewat endpoint media terpusat.
 * Satu Upload = satu file (koleksi 'file' single). Hapus Upload → file ikut terhapus (Spatie).
 */
class Upload extends Model implements HasMedia
{
    use HasUuid7, HasUploadableMedia;

    protected $fillable = [
        'uploaded_by',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('file')->singleFile();
    }
}
