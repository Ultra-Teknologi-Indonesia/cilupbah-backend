<?php

namespace Modules\Bantuan\Services;

use OpenApi\Attributes as OA;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

class ResponseIntrospector
{

    public function forMethod(ReflectionMethod $method, string $httpMethod, ?string $actionName = null): array
    {
        $body = $this->methodSource($method);

        $resourceClass = $body === null ? null : $this->findResourceClass($method, $body);
        if (! $resourceClass) {

            $actionEx = $this->actionExample($actionName, $body);
            if ($actionEx) return $actionEx + ['resource_class' => null];
            $default = $this->defaultWrapper($httpMethod);
            return $default + ['resource_class' => null];
        }

        $oaByProperty = $this->collectOaProperties($method->getDeclaringClass());

        [$schema, $example] = $this->extractFromResource($resourceClass, $oaByProperty);

        $isCollection = str_contains($body, "{$this->shortName($resourceClass)}::collection");

        if ($isCollection) {
            return [
                'schema' => [
                    'success' => 'boolean',
                    'message' => 'string',
                    'data'    => [$schema],
                    'meta'    => [
                        'current_page' => 'integer',
                        'per_page'     => 'integer',
                        'total'        => 'integer',
                        'last_page'    => 'integer',
                    ],
                ],
                'example' => [
                    'success' => true,
                    'message' => 'Berhasil.',
                    'data'    => [$example],
                    'meta'    => [
                        'current_page' => 1,
                        'per_page'     => 10,
                        'total'        => 1,
                        'last_page'    => 1,
                    ],
                ],
                'resource_class' => $resourceClass,
            ];
        }

        return [
            'schema' => [
                'success' => 'boolean',
                'message' => 'string',
                'data'    => $schema,
            ],
            'example' => [
                'success' => true,
                'message' => 'Berhasil.',
                'data'    => $example,
            ],
            'resource_class' => $resourceClass,
        ];
    }

    private function actionExample(?string $action, ?string $body): ?array
    {

        $message = null;
        if ($body && preg_match('/successResponse\s*\([^,]*,\s*[\'"]([^\'"]+)[\'"]/i', $body, $m)) {
            $message = $m[1];
        }

        $actionMap = [
            'cancel'     => 'Berhasil membatalkan.',
            'restore'    => 'Berhasil memulihkan.',
            'archive'    => 'Berhasil mengarsipkan.',
            'activate'   => 'Berhasil mengaktifkan.',
            'deactivate' => 'Berhasil menonaktifkan.',
            'approve'    => 'Berhasil menyetujui.',
            'reject'     => 'Berhasil menolak.',
            'submit'     => 'Berhasil mengajukan.',
            'revert'     => 'Berhasil me-revert.',
            'export'     => 'Ekspor sedang diproses. Cek di Aktivitas Impex.',
            'import'     => 'Impor sedang diproses. Cek di Aktivitas Impex.',
            'sync'       => 'Sinkronisasi selesai.',
            'assign'     => 'Berhasil meng-assign.',
            'unassign'   => 'Berhasil melepas assignment.',
            'ship'       => 'Berhasil mengirim.',
            'receive'    => 'Berhasil menerima.',
            'putaway'    => 'Berhasil menempatkan.',
            'pick'       => 'Berhasil pick.',
            'pack'       => 'Berhasil pack.',
            'print'      => 'PDF berhasil dibuat.',
            'destroy'    => 'Berhasil dihapus.',
            'delete'     => 'Berhasil dihapus.',
        ];

        $matched = null;
        if ($action) {
            $lower = strtolower($action);
            foreach ($actionMap as $k => $v) {
                if (str_contains($lower, $k)) { $matched = $v; break; }
            }
        }
        $finalMessage = $message ?? $matched;

        if ($finalMessage) {
            return [
                'schema' => [
                    'success' => 'boolean',
                    'message' => 'string',
                    'data'    => 'object|null',
                ],
                'example' => [
                    'success' => true,
                    'message' => $finalMessage,
                    'data'    => new \stdClass(),
                ],
            ];
        }
        return null;
    }

    private function defaultWrapper(string $httpMethod): array
    {
        $isEmpty = $httpMethod === 'DELETE';
        return [
            'schema' => [
                'success' => 'boolean',
                'message' => 'string',
                'data'    => $isEmpty ? 'null' : 'mixed',
            ],
            'example' => [
                'success' => true,
                'message' => 'Berhasil.',
                'data'    => $isEmpty ? null : new \stdClass(),
            ],
        ];
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts) ?: $fqcn;
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

    private function findResourceClass(ReflectionMethod $method, string $body): ?string
    {

        $shortNames = [];
        if (preg_match_all('/(?:new\s+|\b)([A-Z]\w*Resource)\b/', $body, $m)) {
            foreach ($m[1] as $n) $shortNames[$n] = true;
        }
        if (! $shortNames) return null;

        $useMap = $this->parseUseStatements($method->getFileName() ?? '');
        foreach (array_keys($shortNames) as $short) {
            if (isset($useMap[$short]) && class_exists($useMap[$short])) {
                return $useMap[$short];
            }
        }

        $ns = $method->getDeclaringClass()->getNamespaceName();
        foreach (array_keys($shortNames) as $short) {
            $candidates = [
                $ns . '\\' . $short,

                str_replace('\\Controllers', '\\Resources', $ns) . '\\' . $short,
            ];
            foreach ($candidates as $c) {
                if (class_exists($c)) return $c;
            }
        }
        return null;
    }

    private function parseUseStatements(string $file): array
    {
        if (! $file || ! is_readable($file)) return [];
        $src = @file_get_contents($file);
        if (! $src) return [];
        $out = [];
        if (preg_match_all('/^use\s+([\\\\\w]+)(?:\s+as\s+(\w+))?\s*;/m', $src, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $fqcn  = ltrim($row[1], '\\');
                $alias = $row[2] ?? '';
                $short = $alias ?: (str_contains($fqcn, '\\') ? substr($fqcn, strrpos($fqcn, '\\') + 1) : $fqcn);
                $out[$short] = $fqcn;
            }
        }
        return $out;
    }

    private function extractFromResource(string $resourceClass, array $oaByProperty): array
    {
        $schema  = [];
        $example = [];
        try {
            $ref = new ReflectionClass($resourceClass);
            if (! $ref->hasMethod('toArray')) return [[], []];
            $method = $ref->getMethod('toArray');
            $src    = $this->methodSource($method);
            if (! $src) return [[], []];

            if (preg_match_all('/[\'"]([a-zA-Z_][\w]*)[\'"]\s*=>/', $src, $m)) {
                foreach ($m[1] as $key) {
                    if (isset($schema[$key])) continue;
                    $oa = $oaByProperty[$key] ?? null;
                    $type = $oa['type'] ?? 'mixed';
                    $schema[$key]  = $type;
                    $example[$key] = $oa['example'] ?? $this->exampleForType($type, $key);
                }
            }
        } catch (Throwable $e) {
            return [[], []];
        }
        return [$schema, $example];
    }

    private function exampleForType(string $type, string $field): mixed
    {
        $t = strtolower($type);
        if (str_ends_with($field, '_id') || $field === 'id') {
            return '019ea2afad1d733eafb905816d10590e';
        }
        return match ($t) {
            'integer', 'int'     => 1,
            'number', 'double', 'float' => 0,
            'boolean', 'bool'    => false,
            'array'              => [],
            'object'             => new \stdClass(),
            'null'               => null,
            default              => 'string',
        };
    }

    private function collectOaProperties(ReflectionClass $class): array
    {
        $out = [];
        try {
            foreach ($class->getAttributes(OA\Schema::class, ReflectionAttribute::IS_INSTANCEOF) as $attr) {
                $args = $attr->getArguments();
                foreach ($args['properties'] ?? [] as $prop) {
                    $data = $this->readOaProperty($prop);
                    if ($data) {
                        $out[$data['name']] = $data;
                    }
                }
            }
        } catch (Throwable $e) {

        }
        return $out;
    }

    private function readOaProperty(mixed $prop): ?array
    {
        try {
            $name    = property_exists($prop, 'property') ? $prop->property : null;
            $type    = property_exists($prop, 'type') ? ($prop->type ?? 'string') : 'string';
            $example = property_exists($prop, 'example') ? $prop->example : null;
            $nullable = property_exists($prop, 'nullable') ? (bool) $prop->nullable : false;
            if (! is_string($name) || $name === '') return null;
            return [
                'name'     => $name,
                'type'     => is_string($type) ? $type : 'mixed',
                'example'  => $example,
                'nullable' => $nullable,
            ];
        } catch (Throwable $e) {
            return null;
        }
    }
}
