<?php

namespace Modules\Channel\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Channel\Models\ChannelShop;

class WooCommerceClient
{
    public function get(ChannelShop $shop, string $path, array $query = []): array
    {
        return $this->decode($this->send($shop, 'GET', $path, $query), $shop, $path);
    }

    public function post(ChannelShop $shop, string $path, array $body = []): array
    {
        return $this->decode($this->send($shop, 'POST', $path, [], $body), $shop, $path);
    }

    public function put(ChannelShop $shop, string $path, array $body = []): array
    {
        return $this->decode($this->send($shop, 'PUT', $path, [], $body), $shop, $path);
    }

    public function delete(ChannelShop $shop, string $path, array $query = ['force' => true]): array
    {
        return $this->decode($this->send($shop, 'DELETE', $path, $query), $shop, $path);
    }

    public function paginate(ChannelShop $shop, string $path, array $query = [], int $perPage = 100, int $maxPages = 100): array
    {
        $items = [];
        $page = 1;

        do {
            $response = $this->send($shop, 'GET', $path, array_merge($query, [
                'per_page' => $perPage,
                'page' => $page,
            ]));

            $chunk = $this->decode($response, $shop, $path);

            if (! is_array($chunk) || empty($chunk)) {
                break;
            }

            foreach ($chunk as $row) {
                $items[] = $row;
            }

            $totalPages = (int) ($response->header('X-WP-TotalPages') ?: 0);
            $page++;
        } while ($totalPages > 0 && $page <= min($totalPages, $maxPages));

        return $items;
    }

    public function send(ChannelShop $shop, string $method, string $path, array $query = [], array $body = []): Response
    {
        $this->assertConfigured($shop);
        $this->throttle();

        $url = $this->baseUrl($shop) . '/' . ltrim($path, '/');

        $request = Http::withBasicAuth((string) $shop->consumer_key, (string) $shop->consumer_secret)
            ->acceptJson()
            ->timeout(30);

        if (! empty($query)) {
            $request = $request->withQueryParameters($query);
        }

        return match (strtoupper($method)) {
            'GET' => $request->get($url),
            'DELETE' => $request->delete($url),
            'PUT' => $request->put($url, $body),
            'PATCH' => $request->patch($url, $body),
            default => $request->post($url, $body),
        };
    }

    protected function decode(Response $response, ChannelShop $shop, string $path): array
    {
        $data = $response->json() ?? [];

        if ($response->failed()) {
            $message = is_array($data) ? ($data['message'] ?? $response->body()) : $response->body();

            Log::error('WooCommerce API Error', [
                'store' => $shop->store_url,
                'path' => $path,
                'status' => $response->status(),
                'message' => $message,
            ]);

            throw new \Exception('WooCommerce API Error: ' . $message);
        }

        return is_array($data) ? $data : [];
    }

    protected function baseUrl(ChannelShop $shop): string
    {
        $version = (string) config('services.woocommerce.api_version', 'wc/v3');

        return rtrim((string) $shop->store_url, '/') . '/wp-json/' . trim($version, '/');
    }

    protected function assertConfigured(ChannelShop $shop): void
    {
        if (! $shop->store_url || ! $shop->consumer_key || ! $shop->consumer_secret) {
            throw new \RuntimeException('Kredensial WooCommerce belum lengkap untuk toko ini.');
        }
    }

    protected function throttle(): void
    {
        $limit = config('channel.api_rate_limit_per_second', 8);

        if (! RateLimiter::attempt('woocommerce-api', $limit, fn () => null, 1)) {
            $wait = RateLimiter::availableIn('woocommerce-api');
            usleep((int) ($wait * 1_000_000) + 50_000);
        }
    }
}
