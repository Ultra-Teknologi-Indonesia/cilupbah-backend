<?php

namespace Modules\Auth\Support;

/**
 * Membaca config/rbac.php dan mengekspansinya menjadi:
 *  - daftar semua nama permission (untuk seeder & validasi)
 *  - struktur matriks bergrup (untuk endpoint /permissions/catalog & FE)
 *  - resolusi token grant default per role
 *
 * Konvensi nama permission: "{action}-{resource}". "extras" ditulis eksplisit.
 */
class PermissionCatalog
{
    /** @return array<string,mixed> */
    public static function config(): array
    {
        return config('rbac', ['actions' => [], 'groups' => [], 'defaults' => []]);
    }

    /**
     * Nama permission untuk satu resource (aksi + extras).
     *
     * @return list<string>
     */
    public static function resourcePermissionNames(array $resource): array
    {
        $names = [];
        foreach ($resource['actions'] ?? [] as $action) {
            $names[] = "{$action}-{$resource['key']}";
        }
        foreach ($resource['extras'] ?? [] as $extra) {
            $names[] = $extra['name'];
        }

        return $names;
    }

    /**
     * Semua nama permission di seluruh katalog (unik, terurut).
     *
     * @return list<string>
     */
    public static function allPermissionNames(): array
    {
        $names = [];
        foreach (self::config()['groups'] ?? [] as $group) {
            foreach ($group['resources'] ?? [] as $resource) {
                foreach (self::resourcePermissionNames($resource) as $name) {
                    $names[$name] = true;
                }
            }
        }

        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /**
     * Struktur matriks siap-render untuk FE. Setiap resource memuat peta
     * aksi => nama permission, plus daftar extras.
     *
     * @return list<array<string,mixed>>
     */
    public static function matrix(): array
    {
        $actionLabels = self::config()['actions'] ?? [];

        return array_map(function (array $group) use ($actionLabels) {
            return [
                'key' => $group['key'],
                'label' => $group['label'],
                'resources' => array_map(function (array $resource) use ($actionLabels) {
                    $actions = array_map(fn (string $action) => [
                        'action' => $action,
                        'label' => $actionLabels[$action] ?? ucfirst($action),
                        'permission' => "{$action}-{$resource['key']}",
                    ], $resource['actions'] ?? []);

                    $extras = array_map(fn (array $extra) => [
                        'permission' => $extra['name'],
                        'label' => $extra['label'],
                    ], $resource['extras'] ?? []);

                    return [
                        'key' => $resource['key'],
                        'label' => $resource['label'],
                        'actions' => $actions,
                        'extras' => $extras,
                    ];
                }, $group['resources'] ?? []),
            ];
        }, self::config()['groups'] ?? []);
    }

    /**
     * Resolusi token grant default (lihat grammar di config/rbac.php).
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    public static function resolveGrants(array $tokens): array
    {
        $resolved = [];

        foreach ($tokens as $token) {
            if ($token === '*') {
                foreach (self::allPermissionNames() as $name) {
                    $resolved[$name] = true;
                }
                continue;
            }

            if (str_starts_with($token, 'group:')) {
                // group:{key}:*
                $groupKey = explode(':', $token)[1] ?? null;
                foreach (self::config()['groups'] ?? [] as $group) {
                    if ($group['key'] !== $groupKey) {
                        continue;
                    }
                    foreach ($group['resources'] ?? [] as $resource) {
                        foreach (self::resourcePermissionNames($resource) as $name) {
                            $resolved[$name] = true;
                        }
                    }
                }
                continue;
            }

            if (str_contains($token, ':')) {
                [$resourceKey, $action] = explode(':', $token, 2);
                $resource = self::findResource($resourceKey);
                if ($resource === null) {
                    continue;
                }
                if ($action === '*') {
                    foreach (self::resourcePermissionNames($resource) as $name) {
                        $resolved[$name] = true;
                    }
                } else {
                    $resolved["{$action}-{$resourceKey}"] = true;
                }
                continue;
            }

            // Nama permission apa adanya.
            $resolved[$token] = true;
        }

        return array_keys($resolved);
    }

    /** @return array<string,mixed>|null */
    public static function findResource(string $resourceKey): ?array
    {
        foreach (self::config()['groups'] ?? [] as $group) {
            foreach ($group['resources'] ?? [] as $resource) {
                if ($resource['key'] === $resourceKey) {
                    return $resource;
                }
            }
        }

        return null;
    }
}
