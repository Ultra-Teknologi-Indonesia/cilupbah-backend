<?php

namespace App\Models;

use App\Concerns\HasUploadableMedia;
use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;

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
