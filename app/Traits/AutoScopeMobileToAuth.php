<?php

namespace App\Traits;

use App\Enums\ClientChannelEnum;
use Illuminate\Http\Request;

trait AutoScopeMobileToAuth
{
    protected function forceMobileScopeToAuth(Request $request, string $filterKey): void
    {
        $channel = $request->attributes->get('client_channel');
        if ($channel !== ClientChannelEnum::MOBILE) {
            return;
        }
        $userId = auth()->id();
        if (! $userId) {
            return;
        }

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
        $channel = $request->attributes->get('client_channel');
        if ($channel === ClientChannelEnum::MOBILE && auth()->id()) {
            return (string) auth()->id();
        }
        return $webValue;
    }
}
