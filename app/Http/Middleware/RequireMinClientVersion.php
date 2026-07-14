<?php

namespace App\Http\Middleware;

use App\Enums\ClientChannelEnum;
use App\Exceptions\ClientVersionTooOldException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireMinClientVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $channel = $request->attributes->get('client_channel');
        if ($channel !== ClientChannelEnum::MOBILE) {
            return $next($request);
        }

        $current = (string) $request->header('X-Client-Version', '0.0.0');
        $minimum = (string) config('warehouse.min_mobile_version', '0.0.0');
        $upgradeUrl = config('warehouse.mobile_upgrade_url');
        $hardGate = (bool) config('warehouse.mobile_version_gate_hard', false);

        if ($this->isOlder($current, $minimum)) {
            if ($hardGate) {
                throw new ClientVersionTooOldException($current, $minimum, $upgradeUrl);
            }

            $response = $next($request);
            $response->headers->set('X-Client-Deprecated', 'true');
            $response->headers->set('X-Minimum-Client-Version', $minimum);
            if ($upgradeUrl) {
                $response->headers->set('X-Upgrade-Url', $upgradeUrl);
            }
            return $response;
        }

        return $next($request);
    }

    private function isOlder(string $current, string $minimum): bool
    {
        $currentParts = $this->parseVersion($current);
        $minimumParts = $this->parseVersion($minimum);

        for ($i = 0; $i < 3; $i++) {
            if (($currentParts[$i] ?? 0) < ($minimumParts[$i] ?? 0)) {
                return true;
            }
            if (($currentParts[$i] ?? 0) > ($minimumParts[$i] ?? 0)) {
                return false;
            }
        }
        return false;
    }

    private function parseVersion(string $version): array
    {
        $parts = explode('.', preg_replace('/[^0-9.]/', '', $version));
        return array_map('intval', array_slice(array_pad($parts, 3, '0'), 0, 3));
    }
}
