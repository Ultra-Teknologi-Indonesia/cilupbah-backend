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

    public static function preload(iterable $actors): void
    {
        $ids = [];

        foreach ($actors as $actor) {
            $id = self::toUserId($actor);

            if ($id !== null && ! array_key_exists($id, self::$cache)) {
                $ids[$id] = true;
            }
        }

        if (empty($ids)) {
            return;
        }

        $names = User::whereIn('id', array_keys($ids))->pluck('name', 'id');

        foreach (array_keys($ids) as $id) {
            self::$cache[$id] = $names[$id] ?? null;
        }
    }

    public static function resolve(?string $actor): ?string
    {
        $id = self::toUserId($actor);

        if ($id === null) {
            return $actor;
        }

        if (! array_key_exists($id, self::$cache)) {
            self::$cache[$id] = optional(User::find($id))->name;
        }

        return self::$cache[$id] ?? $actor;
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    private static function toUserId(?string $actor): ?string
    {
        if ($actor === null || $actor === '') {
            return null;
        }

        $id = preg_replace('/^user:/', '', $actor);

        return self::isUuid($id) ? $id : null;
    }

    private static function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value,
        );
    }
}
