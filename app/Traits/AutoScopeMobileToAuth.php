<?php

namespace App\Traits;

use App\Enums\ClientChannelEnum;
use Illuminate\Http\Request;

trait AutoScopeMobileToAuth
{
    protected function forceMobileScopeToAuth(Request $request, string $filterKey): void
    {
        $user = auth()->user();
        if (! $user || ! $this->isMobileRequest($request)) {
            return;
        }

        if (method_exists($user, 'hasRole') && $user->hasRole('owner')) {
            return;
        }

        $userId = $user->id;

        $filter = $request->query('filter', []);
        if (! is_array($filter)) {
            $filter = [];
        }
        $filter[$filterKey] = (string) $userId;

        $request->query->set('filter', $filter);

        $request->merge(['filter' => $filter]);
    }

    protected function overrideForMobile(Request $request, ?string $webValue): ?string
    {
        $user = auth()->user();

        if ($user
            && $this->isMobileRequest($request)
            && method_exists($user, 'hasRole')
            && ! $user->hasRole('owner')) {
            return (string) $user->id;
        }

        return $webValue;
    }

    private function isMobileRequest(Request $request): bool
    {
        return $request->attributes->get('client_channel') === ClientChannelEnum::MOBILE;
    }
}
