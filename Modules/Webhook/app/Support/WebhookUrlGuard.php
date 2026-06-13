<?php

namespace Modules\Webhook\Support;

/**
 * Guard anti-SSRF untuk URL target webhook.
 *
 * Menolak skema di luar allowlist (default: https) dan host yang menunjuk ke
 * alamat internal (loopback, link-local/metadata 169.254.x, dan rentang privat).
 * Dipakai dua lapis: saat validasi pendaftaran DAN saat pengiriman (anti DNS rebinding).
 */
class WebhookUrlGuard
{
    public function isSafe(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        if (! in_array(strtolower($parts['scheme']), $this->allowedSchemes(), true)) {
            return false;
        }

        $host = trim($parts['host'], '[]');

        foreach ($this->resolveIps($host) as $ip) {
            if (! $this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    protected function resolveIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = @gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (! empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        // Host tidak resolve → tidak bisa di-fetch sekarang; pengiriman akan cek ulang.
        return $ips;
    }

    protected function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * @return array<int, string>
     */
    protected function allowedSchemes(): array
    {
        $schemes = (array) config('webhook.allowed_schemes', ['https']);

        return array_map('strtolower', $schemes ?: ['https']);
    }
}
