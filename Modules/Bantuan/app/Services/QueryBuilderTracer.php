<?php

namespace Modules\Bantuan\Services;

use ReflectionClass;
use ReflectionMethod;
use Throwable;

class QueryBuilderTracer
{

    public function trace(ReflectionMethod $method): array
    {
        $out = [
            'allowed_filters'  => [],
            'allowed_sorts'    => [],
            'allowed_search'   => [],
            'allowed_includes' => [],
            'allowed_fields'   => [],
            'default_sort'     => null,
        ];

        $visited = [];
        $bodies = $this->collectBodies($method, 0, $visited);

        $classes = [];
        foreach (array_keys($visited) as $sig) {
            $cls = explode('::', $sig)[0] ?? '';
            if ($cls) $classes[$cls] = true;
        }

        foreach ($bodies as $body) {
            $this->extractFromBody($body, $out);
        }

        $out['allowed_sorts']    = $this->resolveConstPlaceholders($out['allowed_sorts'], array_keys($classes));
        $out['allowed_search']   = $this->resolveConstPlaceholders($out['allowed_search'], array_keys($classes));
        $out['allowed_includes'] = $this->resolveConstPlaceholders($out['allowed_includes'], array_keys($classes));
        $out['allowed_fields']   = $this->resolveConstPlaceholders($out['allowed_fields'], array_keys($classes));

        foreach (['allowed_sorts', 'allowed_search', 'allowed_includes', 'allowed_fields'] as $k) {
            $out[$k] = array_values(array_unique($out[$k]));
        }

        $seen = [];
        $dedup = [];
        foreach ($out['allowed_filters'] as $f) {
            if (isset($seen[$f['name']])) continue;
            $seen[$f['name']] = true;
            $dedup[] = $f;
        }
        $out['allowed_filters'] = $dedup;

        return $out;
    }

    private function collectBodies(ReflectionMethod $method, int $depth = 0, array &$visited = []): array
    {
        $bodies = [];
        if ($depth > 3) return $bodies;

        $key = $method->getDeclaringClass()->getName() . '::' . $method->getName();
        if (isset($visited[$key])) return $bodies;
        $visited[$key] = true;

        $main = $this->methodSource($method);
        if ($main === null) return $bodies;
        $bodies[] = $main;

        $class = $method->getDeclaringClass();
        $props = $this->collectPropertyTypes($class);

        if (preg_match_all('/\$this->(\w+)->(\w+)\s*\(/', $main, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $propName   = $row[1];
                $methodName = $row[2];
                $targetClass = $props[$propName] ?? null;
                if (! $targetClass || ! class_exists($targetClass)) continue;
                try {
                    $ref = new ReflectionClass($targetClass);
                    if (! $ref->hasMethod($methodName)) continue;
                    $target = $ref->getMethod($methodName);
                    $bodies = array_merge($bodies, $this->collectBodies($target, $depth + 1, $visited));
                } catch (Throwable $e) {
                    continue;
                }
            }
        }

        if (preg_match_all('/\$this->(\w+)\s*\(/', $main, $m2, PREG_SET_ORDER)) {
            foreach ($m2 as $row) {
                $methodName = $row[1];
                if (! $class->hasMethod($methodName)) continue;
                if ($methodName === $method->getName()) continue;
                try {
                    $target = $class->getMethod($methodName);
                    $bodies = array_merge($bodies, $this->collectBodies($target, $depth + 1, $visited));
                } catch (Throwable $e) {
                    continue;
                }
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
        } catch (Throwable $e) {

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

    private function extractFromBody(string $body, array &$out): void
    {

        $flat = preg_replace('/\s+/', ' ', $body);

        foreach ($this->matchCallArgs($flat, 'allowedFilters') as $args) {
            foreach ($this->splitAllowedFilterEntries($args) as $entry) {
                $filter = $this->parseAllowedFilterEntry($entry);
                if ($filter) {
                    $out['allowed_filters'][] = $filter;
                }
            }
        }

        foreach ($this->matchCallArgs($flat, 'allowedSorts') as $args) {
            foreach ($this->extractStringArgs($args) as $s) {
                $out['allowed_sorts'][] = $s;
            }

            if (preg_match_all('/\.\.\.(self|static)::(\w+)/', $args, $m)) {
                foreach ($m[2] as $const) {

                    $out['allowed_sorts'][] = "@{$const}";
                }
            }
        }

        foreach ($this->matchCallArgs($flat, 'allowedSearch') as $args) {
            foreach ($this->extractStringArgs($args) as $s) {
                $out['allowed_search'][] = $s;
            }
        }

        foreach ($this->matchCallArgs($flat, 'allowedIncludes') as $args) {
            foreach ($this->extractStringArgs($args) as $s) {
                $out['allowed_includes'][] = $s;
            }
        }

        foreach ($this->matchCallArgs($flat, 'allowedFields') as $args) {
            foreach ($this->extractStringArgs($args) as $s) {
                $out['allowed_fields'][] = $s;
            }
        }

        if (preg_match('/defaultSort\s*\(\s*[\'"]([^\'"]+)[\'"]/', $flat, $m)) {
            $out['default_sort'] = $out['default_sort'] ?? $m[1];
        }
    }

    private function matchCallArgs(string $source, string $name): array
    {
        $out = [];
        $len = strlen($source);
        $pos = 0;
        while (($p = strpos($source, $name . '(', $pos)) !== false) {
            $start = $p + strlen($name) + 1;
            $depth = 1;
            $i = $start;
            $inStr = null;
            $escaped = false;
            while ($i < $len && $depth > 0) {
                $c = $source[$i];
                if ($inStr) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($c === '\\') {
                        $escaped = true;
                    } elseif ($c === $inStr) {
                        $inStr = null;
                    }
                } else {
                    if ($c === '"' || $c === "'") {
                        $inStr = $c;
                    } elseif ($c === '(') {
                        $depth++;
                    } elseif ($c === ')') {
                        $depth--;
                    }
                }
                $i++;
            }
            if ($depth === 0) {
                $out[] = substr($source, $start, $i - $start - 1);
                $pos   = $i;
            } else {
                break;
            }
        }
        return $out;
    }

    private function splitAllowedFilterEntries(string $args): array
    {
        $out = [];
        $len = strlen($args);
        $depth = 0;
        $buf = '';
        $inStr = null;
        $escaped = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $args[$i];
            if ($inStr) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($c === '\\') {
                    $escaped = true;
                } elseif ($c === $inStr) {
                    $inStr = null;
                }
                $buf .= $c;
                continue;
            }
            if ($c === '"' || $c === "'") {
                $inStr = $c;
                $buf .= $c;
                continue;
            }
            if ($c === '(') $depth++;
            if ($c === ')') $depth--;
            if ($c === ',' && $depth === 0) {
                $trim = trim($buf);
                if ($trim !== '') $out[] = $trim;
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        $trim = trim($buf);
        if ($trim !== '') $out[] = $trim;
        return $out;
    }

    private function parseAllowedFilterEntry(string $entry): ?array
    {

        if (preg_match('/AllowedFilter::(\w+)\s*\((.*)\)$/s', $entry, $m)) {
            $type = strtolower($m[1]);
            $inside = $m[2];
            $parts  = $this->splitAllowedFilterEntries($inside);
            $name = $this->unquoteFirst($parts[0] ?? '');
            $col  = isset($parts[1]) ? $this->unquoteFirst($parts[1]) : null;
            $scope = ($type === 'scope') ? ($col ?? $name) : null;
            return [
                'name'   => $name,
                'type'   => $type,
                'column' => $col && $type !== 'scope' && $type !== 'callback' ? $col : null,
                'scope'  => $scope,
            ];
        }

        $s = $this->unquoteFirst($entry);
        if ($s !== '') {
            return ['name' => $s, 'type' => 'exact', 'column' => null, 'scope' => null];
        }
        return null;
    }

    private function unquoteFirst(string $s): string
    {
        $s = trim($s);
        if (preg_match('/^[\'"]([^\'"]*)[\'"]/', $s, $m)) return $m[1];
        return '';
    }

    private function resolveConstPlaceholders(array $list, array $classes): array
    {
        $out = [];
        foreach ($list as $item) {
            if (! str_starts_with($item, '@')) {
                $out[] = $item;
                continue;
            }
            $constName = substr($item, 1);
            $resolved  = null;
            foreach ($classes as $cls) {
                try {
                    if (defined("{$cls}::{$constName}")) {
                        $resolved = constant("{$cls}::{$constName}");
                        break;
                    }
                    $ref = new ReflectionClass($cls);
                    if ($ref->hasConstant($constName)) {
                        $resolved = $ref->getConstant($constName);
                        break;
                    }
                } catch (Throwable $e) {
                    continue;
                }
            }
            if (is_array($resolved)) {
                foreach ($resolved as $v) {
                    if (is_string($v)) $out[] = $v;
                }
            } elseif (is_string($resolved)) {
                $out[] = $resolved;
            } else {
                $out[] = $item; 
            }
        }
        return $out;
    }

    private function extractStringArgs(string $args): array
    {
        $out = [];
        if (preg_match_all('/[\'"]([^\'"]+)[\'"]/', $args, $m)) {
            foreach ($m[1] as $s) $out[] = $s;
        }
        return $out;
    }
}
