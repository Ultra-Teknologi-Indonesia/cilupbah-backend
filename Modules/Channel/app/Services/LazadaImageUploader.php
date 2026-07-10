<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;

class LazadaImageUploader
{
    public function __construct(
        protected LazadaClient $client,
    ) {}

    public function uploadFromUrls(array $urls, string $accessToken): array
    {
        return $this->upload($urls, $accessToken)['urls'];
    }

    public function upload(array $urls, string $accessToken): array
    {
        $migrated = [];
        $errors = [];

        foreach ($urls as $url) {
            if (! $this->isSupportedFormat($url)) {
                $errors[] = ['url' => $url, 'reason' => 'format tidak didukung (hanya JPG/PNG)'];
                Log::warning("Lazada image migrate: format tidak didukung, dilewati: {$url}");
                continue;
            }

            try {

                $res = $this->client->request('POST', '/image/migrate', [
                    'image_url' => $url,
                ], $accessToken);

                $migratedUrl = $res['data']['image']['url'] ?? $res['data']['url'] ?? null;

                if ($migratedUrl) {
                    $migrated[$url] = $migratedUrl;
                } else {
                    $errors[] = ['url' => $url, 'reason' => 'respons migrate tak terduga'];
                    Log::warning('Lazada image migrate respons tak terduga: ' . json_encode($res));
                }
            } catch (\Throwable $e) {
                $errors[] = ['url' => $url, 'reason' => $e->getMessage()];
                Log::error("Lazada image migrate gagal untuk {$url}: {$e->getMessage()}");
            }
        }

        return ['urls' => array_values($migrated), 'map' => $migrated, 'errors' => $errors];
    }

    protected function isSupportedFormat(string $url): bool
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png'], true);
    }
}
