<?php

namespace Modules\Bantuan\Services;

use ReflectionClass;
use ReflectionMethod;
use Throwable;

class QueryParamExtractor
{
    private const IGNORE_NAMES = [
        'filter', 'sort', 'search', 'include', 'fields',
        'page', 'per_page', 'perPage', 'q',
    ];

    public function __construct(private FieldDescriptionResolver $descriptions = new FieldDescriptionResolver()) {}

    public function extract(?ReflectionMethod $method): array
    {
        if (! $method) return [];

        $visited = [];
        $bodies  = $this->collectBodies($method, 0, $visited);
        if (! $bodies) return [];
        $src = implode("\n", $bodies);

        $found = [];

        $patterns = [
            "/request\s*\(\s*['\"]([\w\.-]+)['\"]/",
            "/request\s*\(\s*\)\s*->\s*(?:query|input|get|boolean|integer|string|date|float|filled)\s*\(\s*['\"]([\w\.-]+)['\"]/",
            "/\\\$request\s*->\s*(?:query|input|get|boolean|integer|string|date|float|filled)\s*\(\s*['\"]([\w\.-]+)['\"]/",
        ];
        foreach ($patterns as $p) {
            if (preg_match_all($p, $src, $m)) {
                foreach ($m[1] as $name) {
                    $base = explode('.', $name)[0];
                    if (in_array($base, self::IGNORE_NAMES, true)) continue;
                    if (str_starts_with($base, 'filter')) continue;
                    $found[$name] = true;
                }
            }
        }

        $out = [];
        foreach (array_keys($found) as $name) {
            $out[] = [
                'name'        => $name,
                'type'        => 'string',
                'description' => $this->descriptions->describe($name, 'string'),
                'required'    => false,
            ];
        }
        return $out;
    }

    private function methodSource(ReflectionMethod $method): ?string
    {
        $file = $method->getFileName();
        if (! $file || ! is_readable($file)) return null;
        $start = $method->getStartLine();
        $end   = $method->getEndLine();
        if (! $start || ! $end) return null;
        $lines = @file($file);
        if (! $lines) return null;
        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }

    private function collectBodies(ReflectionMethod $method, int $depth, array &$visited): array
    {
        if ($depth > 3) return [];
        $key = $method->getDeclaringClass()->getName() . '::' . $method->getName();
        if (isset($visited[$key])) return [];
        $visited[$key] = true;

        $main = $this->methodSource($method);
        if ($main === null) return [];
        $bodies = [$main];

        $class = $method->getDeclaringClass();
        $props = $this->collectPropertyTypes($class);

        if (preg_match_all('/\$this->(\w+)->(\w+)\s*\(/', $main, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $propName   = $row[1];
                $methodName = $row[2];
                $target     = $props[$propName] ?? null;
                if (! $target || ! class_exists($target)) continue;
                try {
                    $ref = new ReflectionClass($target);
                    if (! $ref->hasMethod($methodName)) continue;
                    $bodies = array_merge($bodies, $this->collectBodies($ref->getMethod($methodName), $depth + 1, $visited));
                } catch (Throwable $e) {}
            }
        }
        return $bodies;
    }

    private function collectPropertyTypes(ReflectionClass $class): array
    {
        $out = [];
        try {
            $ctor = $class->getConstructor();
            if ($ctor) {
                foreach ($ctor->getParameters() as $p) {
                    if (! $p->isPromoted()) continue;
                    $type = $p->getType();
                    if ($type && ! $type->isBuiltin() && method_exists($type, 'getName')) {
                        $out[$p->getName()] = $type->getName();
                    }
                }
            }
            foreach ($class->getProperties() as $prop) {
                $type = $prop->getType();
                if ($type && ! $type->isBuiltin() && method_exists($type, 'getName')) {
                    $out[$prop->getName()] = $type->getName();
                }
            }
        } catch (Throwable $e) {}
        return $out;
    }
}
