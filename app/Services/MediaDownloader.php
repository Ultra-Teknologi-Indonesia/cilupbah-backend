<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Spatie\MediaLibrary\Downloaders\Downloader;
use Spatie\MediaLibrary\MediaCollections\Exceptions\UnreachableUrl;

class MediaDownloader implements Downloader
{
    public function getTempFile(string $url): string
    {
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'image/*,video/*,*/*;q=0.8',
        ])
            ->timeout(30)
            ->connectTimeout(10)
            ->withOptions(['allow_redirects' => ['max' => 5]])
            ->get($url);

        if (! $response->successful()) {
            throw UnreachableUrl::create($url);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'media-library');
        file_put_contents($tempFile, $response->body());

        if (filesize($tempFile) < 100) {
            @unlink($tempFile);
            throw UnreachableUrl::create($url);
        }

        return $tempFile;
    }
}
