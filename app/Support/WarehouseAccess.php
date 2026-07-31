<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

class WarehouseAccess
{
    public static function allowedIds(): ?array
    {
        $user = Auth::user();

        return $user instanceof User ? $user->allowedLocationIds() : null;
    }

    public static function isRestricted(): bool
    {
        return self::allowedIds() !== null;
    }

    public static function apply($query, string $column = 'location_id')
    {
        $ids = self::allowedIds();

        if ($ids !== null) {
            $query->whereIn($column, $ids);
        }

        return $query;
    }

    public static function assert(?string $locationId): void
    {
        $ids = self::allowedIds();

        if ($ids !== null && $locationId !== null && ! in_array($locationId, $ids, true)) {
            throw new AuthorizationException('Anda tidak memiliki akses ke gudang ini.');
        }
    }

    public static function constrain(?array $requested): ?array
    {
        $ids = self::allowedIds();

        if ($ids === null) {
            return $requested;
        }

        if ($requested === null || $requested === []) {
            return $ids;
        }

        return array_values(array_intersect($requested, $ids));
    }
}
