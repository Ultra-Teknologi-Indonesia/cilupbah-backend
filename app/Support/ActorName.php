<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

class ActorName
{
    private static array $cache = [];

    public static function fromUser(?Authenticatable $user, string $fallback = 'system'): string
    {
        if ($user === null) {
            return $fallback;
        }

        return $user->name ?? $user->email ?? $fallback;
    }

    public static function resolve(?string $actor): ?string
    {
        if ($actor === null || $actor === '') {
            return $actor;
        }

        $id = preg_replace('/^user:/', '', $actor);

        if (! self::isUuid($id)) {
            return $actor;
        }

        if (! array_key_exists($id, self::$cache)) {
            self::$cache[$id] = optional(User::find($id))->name;
        }

        return self::$cache[$id] ?? $actor;
    }

    private static function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value,
        );
    }
}
