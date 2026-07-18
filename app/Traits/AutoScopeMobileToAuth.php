<?php

namespace App\Traits;

use App\Enums\ClientChannelEnum;
use Illuminate\Http\Request;

/**
 * Kalau request berasal dari mobile (header X-Client-Channel: MOBILE
 * ter-resolve di ResolveClientChannel middleware), paksa filter user
 * di query jadi auth()->id() — mencegah spoofing dan supaya mobile
 * tidak perlu tahu UID-nya sendiri.
 *
 * Contoh:
 *   $this->forceMobileScopeToAuth($request, 'picker_id');
 *   → mutate ?filter[picker_id]=<uid> berdasarkan auth()->id().
 */
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

        // Mutate query bag supaya kode yang baca request()->query('filter.<key>')
        // atau Spatie QueryBuilder ->allowedFilters(AllowedFilter::exact($filterKey))
        // ikut ter-scope.
        $request->query->set('filter', $filter);
        // Kalau ada consumer yang baca via all(), replace jaga-jaga.
        $request->merge(['filter' => $filter]);
    }

    /**
     * Convenience: untuk request mobile, kembalikan auth()->id() sebagai
     * override — untuk value non-filter (mis. received_by di body POST).
     * Web tetap pakai value yang dikirim.
     */
    protected function overrideForMobile(Request $request, ?string $webValue): ?string
    {
        $channel = $request->attributes->get('client_channel');
        if ($channel === ClientChannelEnum::MOBILE && auth()->id()) {
            return (string) auth()->id();
        }
        return $webValue;
    }
}
