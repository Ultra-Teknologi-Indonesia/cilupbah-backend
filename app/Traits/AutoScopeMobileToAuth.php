<?php

namespace App\Traits;

use Illuminate\Http\Request;

trait AutoScopeMobileToAuth
{
    protected function forceMobileScopeToAuth(Request $request, string $filterKey): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        // Asumsi: owner/admin berhak melihat semua data tanpa batasan.
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
        
        if ($user && method_exists($user, 'hasRole') && !$user->hasRole('owner')) {
            return (string) $user->id;
        }
        
        return $webValue;
    }
}
