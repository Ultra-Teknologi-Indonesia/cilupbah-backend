<?php

namespace Modules\Auth\Support;

class PermissionCatalog
{

    public static function config(): array
    {
        return config('rbac', ['actions' => [], 'groups' => [], 'defaults' => []]);
    }

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

            $resolved[$token] = true;
        }

        return array_keys($resolved);
    }

    public static function withViewPrerequisites(array $names): array
    {
        $map = self::viewPrerequisiteMap();
        $result = [];

        foreach ($names as $name) {
            $result[$name] = true;
            if (isset($map[$name])) {
                $result[$map[$name]] = true;
            }
        }

        return array_keys($result);
    }

    private static function viewPrerequisiteMap(): array
    {
        $map = [];

        foreach (self::config()['groups'] ?? [] as $group) {
            foreach ($group['resources'] ?? [] as $resource) {
                if (! in_array('view', $resource['actions'] ?? [], true)) {
                    continue;
                }

                $viewName = "view-{$resource['key']}";
                foreach (self::resourcePermissionNames($resource) as $name) {
                    if ($name !== $viewName) {
                        $map[$name] = $viewName;
                    }
                }
            }
        }

        return $map;
    }

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
