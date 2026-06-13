<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'url' => $this->getUrl(),
            'file_name' => $this->file_name,
            'original_name' => $this->getCustomProperty('original_name'),
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'created_at' => $this->created_at,
        ];
    }
}
