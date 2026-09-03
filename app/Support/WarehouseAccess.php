<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\Location;

class WarehouseAccess
{
    private static ?string $systemTransitLocationId = null;

    private static bool $systemTransitLocationResolved = false;

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

    public static function assertOperational(?string $locationId): void
    {

        if (self::allowedIds() === null) {
            return;
        }

        if ($locationId !== null && self::isSystemTransit($locationId)) {
            return;
        }

        self::assert($locationId);
    }

    private static function isSystemTransit(string $locationId): bool
    {
        if (! self::$systemTransitLocationResolved) {
            self::$systemTransitLocationResolved = true;
            self::$systemTransitLocationId = DB::table('locations')
                ->where('location_code', Location::SYSTEM_TRANSIT_CODE)
                ->value('id');
        }

        return self::$systemTransitLocationId !== null
            && (string) self::$systemTransitLocationId === (string) $locationId;
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
