<?php

namespace Modules\Bantuan\Services;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Generator dokumentasi API — dipanggil oleh command bantuan:export-docs.
 *
 * Sumber data:
 *  - Route::getRoutes() untuk enumerasi endpoint api.*
 *  - Reflection ke controller action untuk mengambil FormRequest & PHPDoc
 *  - FormRequest::rules() untuk body_schema + validation + body_example
 *  - Middleware permission:* / role_or_permission:* untuk roles
 *  - Grep controller body untuk side_effects (Event/Job/Notification dispatch)
 */
class ApiDocsGenerator
{
    public function __construct(
        private QueryBuilderTracer $tracer = new QueryBuilderTracer(),
        private ResponseIntrospector $responder = new ResponseIntrospector(),
        private FieldDescriptionResolver $fields = new FieldDescriptionResolver(),
        private RouteNarrator $narrator = new RouteNarrator(),
        private EnumResolver $enums = new EnumResolver(),
        private QueryParamExtractor $queryParamExtractor = new QueryParamExtractor(),
    ) {}

    /** Prefix path yang di-export (Route uri diawali `api/`). */
    private const API_PREFIX = 'api/';

    /** Nama modul yang di-skip dari output (internal only). */
    private const SKIPPED_MODULES = ['Bantuan', 'AI'];

    /** Statik daftar error yang default disertakan untuk endpoint auth. */
    private const DEFAULT_AUTH_ERRORS = [
        [
            'status'         => 401,
            'reason'         => 'Belum terautentikasi (token tidak ada / kedaluwarsa)',
            'body'           => ['message' => 'Unauthenticated.'],
            'terjemahan'     => 'Sesi Anda sudah berakhir. Silakan login ulang.',
        ],
        [
            'status'         => 403,
            'reason'         => 'Hak akses ditolak (permission Spatie tidak terpenuhi)',
            'body'           => ['message' => 'This action is unauthorized.'],
            'terjemahan'     => 'Anda tidak memiliki izin untuk melakukan aksi ini. Hubungi Admin untuk penambahan izin.',
        ],
    ];

    /**
     * Bangun struktur dokumentasi penuh.
     *
     * @return array{
     *   generated_at:string, version:string,
     *   totals:array{modules:int,endpoints:int,undocumented:int},
     *   modules:array<int,array>
     * }
     */
    public function build(): array
    {
        $endpoints = $this->collectEndpoints();
        $this->attachRelatedEndpoints($endpoints);

        $byModule = [];
        foreach ($endpoints as $endpoint) {
            $mod = $endpoint['module_slug'];
            $byModule[$mod]['name']       = $endpoint['module_name'];
            $byModule[$mod]['slug']       = $endpoint['module_slug'];
            $byModule[$mod]['endpoints'][] = $endpoint;
        }
        ksort($byModule);

        $modules = [];
        $totalUndoc = 0;
        foreach ($byModule as $slug => $data) {
            $eps = $data['endpoints'];
            usort($eps, fn ($a, $b) => strcmp($a['path'], $b['path']) ?: strcmp($a['method'], $b['method']));

            $undoc = 0;
            foreach ($eps as $e) {
                if (! empty($e['needs_doc'])) {
                    $undoc++;
                }
            }
            $totalUndoc += $undoc;

            $modules[] = [
                'slug'             => $slug,
                'name'             => $data['name'],
                'description'      => $this->moduleDescription($slug),
                'endpoints_count'  => count($eps),
                'undocumented'     => $undoc,
                'endpoints'        => $eps,
            ];
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'version'      => '1.0.0',
            'totals'       => [
                'modules'      => count($modules),
                'endpoints'    => count($endpoints),
                'undocumented' => $totalUndoc,
            ],
            'modules' => $modules,
        ];
    }

    /**
     * Bangun 1 file JSON kecil per modul (untuk lazy-load di FE).
     *
     * @return array<string,array>
     */
    public function buildPerModule(): array
    {
        $full = $this->build();

        $index = [
            'generated_at' => $full['generated_at'],
            'version'      => $full['version'],
            'totals'       => $full['totals'],
            'modules'      => [],
        ];

        $files = [];
        foreach ($full['modules'] as $mod) {
            $index['modules'][] = [
                'slug'            => $mod['slug'],
                'name'            => $mod['name'],
                'description'     => $mod['description'],
                'endpoints_count' => $mod['endpoints_count'],
                'undocumented'    => $mod['undocumented'],
            ];
            $files["modules/{$mod['slug']}.json"] = $mod;
        }
        $files['index.json'] = $index;

        return $files;
    }

    /** @return array<int,array> */
    private function collectEndpoints(): array
    {
        $routes = RouteFacade::getRoutes();
        $out    = [];

        foreach ($routes as $route) {
            /** @var Route $route */
            $uri = $route->uri();
            if (! Str::startsWith($uri, self::API_PREFIX)) {
                continue;
            }

            $module = $this->moduleFromRoute($route);
            if ($module === null || in_array($module, self::SKIPPED_MODULES, true)) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $out[] = $this->describeEndpoint($route, $method, $module);
            }
        }

        return $out;
    }

    private function describeEndpoint(Route $route, string $method, string $module): array
    {
        $action     = $route->getActionName();
        $controller = null;
        $actionName = null;

        if ($action !== 'Closure' && str_contains($action, '@')) {
            [$controller, $actionName] = explode('@', $action, 2);
        }

        $reflection = null;
        if ($controller && class_exists($controller)) {
            try {
                $reflection = new ReflectionMethod($controller, $actionName);
            } catch (Throwable $e) {
                $reflection = null;
            }
        }

        $phpDoc = $reflection ? $this->parsePhpDoc($reflection->getDocComment() ?: '') : ['summary' => '', 'description' => '', 'purpose' => '', 'deprecated' => false, 'since' => null];

        // Fallback narasi via RouteNarrator bila PHPDoc kosong
        $middlewareForNarrator = $reflection ? $route->gatherMiddleware() : [];
        $rolesForNarrator      = $this->extractRoles($middlewareForNarrator);
        $formRequestForNarrator = $reflection ? $this->firstFormRequestParam($reflection) : null;
        $needsPhpDocNarration  = $phpDoc['summary'] === '' && $phpDoc['description'] === '' && $phpDoc['purpose'] === '';
        $narration             = $needsPhpDocNarration
            ? $this->narrator->narrate($route, $method, $actionName, $formRequestForNarrator, $rolesForNarrator)
            : null;

        $formRequestClass = $reflection ? $this->firstFormRequestParam($reflection) : null;
        $rules            = $formRequestClass ? $this->extractRules($formRequestClass) : [];

        $bodySchema  = $this->enrichSchemaDescriptions($this->rulesToSchema($rules));
        $bodyExample = $this->rulesToExample($rules);
        $validation  = $this->rulesToFlatList($rules);

        $pathParams  = $this->enrichPathParamDescriptions($this->extractPathParams($route));

        // Query params: gabung dari PHPDoc @queryParam + auto-extract dari body
        $queryParams = $this->extractQueryParams($reflection);
        $autoQuery   = $this->queryParamExtractor->extract($reflection);
        $existing    = array_column($queryParams, 'name');
        foreach ($autoQuery as $qp) {
            if (! in_array($qp['name'], $existing, true)) {
                $queryParams[] = $qp;
            }
        }

        $middleware  = $route->gatherMiddleware();
        $auth        = $this->extractAuth($middleware);
        $roles       = $this->extractRoles($middleware);
        $rateLimit   = $this->extractRateLimit($middleware);

        // Spatie QueryBuilder allowed* (hanya relevan untuk GET/list-style)
        $qb = ($method === 'GET' && $reflection) ? $this->tracer->trace($reflection) : [
            'allowed_filters'  => [],
            'allowed_sorts'    => [],
            'allowed_search'   => [],
            'allowed_includes' => [],
            'allowed_fields'   => [],
            'default_sort'     => null,
        ];
        $qb['allowed_filters'] = $this->enrichFilterDescriptions($qb['allowed_filters'], strtolower($module));

        $responseSuccess = $reflection
            ? $this->responder->forMethod($reflection, $method, $actionName)
            : $this->inferSuccessResponse($reflection, $method);
        $responseErrors  = $this->inferErrorResponses($reflection, $auth);
        $statusCodes     = $this->inferStatusCodes($method, $responseErrors);

        $sideEffects  = $this->inferSideEffects($reflection);
        $relatedPage  = $this->guessRelatedPage($route->getName());

        // needs_doc: hanya true bila PHPDoc kosong DAN narrator gagal
        $needsDoc = $narration === null && $phpDoc['summary'] === '' && $phpDoc['description'] === '';

        return [
            'id'                       => $route->getName() ?: strtolower($method) . '_' . str_replace(['/', '{', '}'], ['_', '', ''], $route->uri()),
            'module_slug'              => strtolower($module),
            'module_name'              => $module,
            'method'                   => $method,
            'path'                     => '/' . ltrim($route->uri(), '/'),
            'summary'                  => $phpDoc['summary'] ?: ($narration['summary'] ?? $this->humanizeAction($actionName ?? '', $route)),
            'description'              => $phpDoc['description'] ?: ($narration['description'] ?? ''),
            'purpose'                  => $phpDoc['purpose'] ?: ($narration['purpose'] ?? $this->guessPurpose($route, $method)),
            'controller'               => $controller,
            'action'                   => $actionName,
            'auth'                     => $auth,
            'roles'                    => $roles,
            'rate_limit'               => $rateLimit,
            'deprecated'               => $phpDoc['deprecated'],
            'since_version'            => $phpDoc['since'],
            'form_request'             => $formRequestClass,
            'path_params'              => $pathParams,
            'query_params'             => $queryParams,
            'body_schema'              => $bodySchema,
            'body_example'             => $bodyExample,
            'validation'               => $validation,
            'response_success_schema'  => $responseSuccess['schema'],
            'response_success_example' => $responseSuccess['example'],
            'response_resource'        => $responseSuccess['resource_class'] ?? null,
            'response_errors'          => $responseErrors,
            'status_codes'             => $statusCodes,
            'side_effects'             => $sideEffects,
            'allowed_filters'          => $qb['allowed_filters'],
            'allowed_sorts'            => $qb['allowed_sorts'],
            'allowed_search'           => $qb['allowed_search'],
            'allowed_includes'         => $qb['allowed_includes'],
            'allowed_fields'           => $qb['allowed_fields'],
            'default_sort'             => $qb['default_sort'],
            'related_endpoints'        => [],
            'related_page'             => $relatedPage,
            'related_manual'           => null,
            'needs_doc'                => $needsDoc,
        ];
    }

    // ============================================================
    // Module detection
    // ============================================================

    private function moduleFromRoute(Route $route): ?string
    {
        $action = $route->getActionName();
        if ($action === 'Closure') {
            return null;
        }
        $ctrl = explode('@', $action, 2)[0] ?? null;
        if (! $ctrl) {
            return null;
        }
        if (preg_match('#^Modules\\\\([^\\\\]+)\\\\#', $ctrl, $m)) {
            return $m[1];
        }
        if (str_starts_with($ctrl, 'App\\Http\\Controllers\\')) {
            return 'App';
        }
        return null;
    }

    private function moduleDescription(string $slug): string
    {
        return match ($slug) {
            'auth'         => 'Autentikasi, pengguna, role/permission (RBAC), profil saya.',
            'channel'      => 'Integrasi marketplace: Shopee, Lazada, TikTok, WooCommerce.',
            'dashboard'    => 'KPI, ringkasan, antrian pekerjaan.',
            'finance'      => 'Kas & bank, jurnal, akun COA, mapping akuntansi.',
            'inbound'      => 'Penerimaan barang (dari PO / Transfer masuk) & penempatan (putaway).',
            'inventory'    => 'Stok, mutasi, transfer bin, transfer antar gudang, penyesuaian, opname.',
            'issuetracker' => 'Pelacak isu internal (bug/enhancement).',
            'notification' => 'Notifikasi in-app, WA, email.',
            'outbound'     => 'Picking, packing, shipping, kurir, resi.',
            'product'      => 'Produk, varian, bundle, mapping channel, kategori, merek.',
            'purchase'     => 'Pesanan pembelian (PO), tagihan (bill), pembayaran.',
            'region'       => 'Master wilayah (provinsi, kota, kecamatan, kelurahan).',
            'report'       => 'Laporan HPP, persediaan, retur, stok minus, dsb.',
            'sales'        => 'Pesanan penjualan, retur, invoice, pembayaran, toko internal.',
            'supplier'     => 'Kontak pemasok & pelanggan.',
            'tax'          => 'Master pajak.',
            'warehouse'    => 'Gudang, zona, rak (bin), lokasi.',
            'warranty'     => 'Garansi produk.',
            'webhook'      => 'Endpoint webhook masuk dari channel.',
            'app'          => 'Endpoint global (di luar modul).',
            default        => '',
        };
    }

    // ============================================================
    // PHPDoc parser
    // ============================================================

    /** @return array{summary:string, description:string, purpose:string, deprecated:bool, since:?string} */
    private function parsePhpDoc(string $doc): array
    {
        $out = ['summary' => '', 'description' => '', 'purpose' => '', 'deprecated' => false, 'since' => null];
        if ($doc === '') {
            return $out;
        }

        $lines = preg_split('/\r?\n/', $doc) ?: [];
        $body  = [];
        foreach ($lines as $line) {
            $line = trim($line);
            $line = preg_replace('#^/\*+\s?#', '', $line);
            $line = preg_replace('#\s*\*/$#', '', $line);
            $line = preg_replace('#^\*\s?#', '', $line);
            if ($line === '' && $body === []) {
                continue;
            }
            $body[] = $line;
        }

        $summaryLines = [];
        $descLines    = [];
        $inDesc       = false;
        foreach ($body as $line) {
            if (str_starts_with($line, '@')) {
                if (preg_match('/^@purpose\s+(.*)$/i', $line, $m)) {
                    $out['purpose'] = trim($m[1]);
                }
                if (preg_match('/^@deprecated/i', $line)) {
                    $out['deprecated'] = true;
                }
                if (preg_match('/^@since\s+(\S+)/i', $line, $m)) {
                    $out['since'] = trim($m[1]);
                }
                continue;
            }
            if ($line === '') {
                if ($summaryLines !== []) {
                    $inDesc = true;
                }
                continue;
            }
            if (! $inDesc) {
                $summaryLines[] = $line;
            } else {
                $descLines[] = $line;
            }
        }
        $out['summary']     = trim(implode(' ', $summaryLines));
        $out['description'] = trim(implode("\n", $descLines));
        return $out;
    }

    private function humanizeAction(string $action, Route $route): string
    {
        if ($action === '') {
            return '';
        }
        $map = [
            'index'   => 'Daftar',
            'show'    => 'Detail',
            'store'   => 'Buat',
            'update'  => 'Ubah',
            'destroy' => 'Hapus',
            'all'     => 'Semua (tanpa paginasi)',
            'export'  => 'Ekspor',
            'import'  => 'Impor',
        ];
        $verb = $map[$action] ?? Str::title(preg_replace('/(?<!^)([A-Z])/', ' $1', $action));

        $resource = preg_replace('#^api/[^/]+/#', '', $route->uri());
        $resource = preg_replace('#\{[^}]+\}#', '', $resource);
        $resource = trim(str_replace('/', ' ', $resource));

        return trim("{$verb} {$resource}");
    }

    private function guessPurpose(Route $route, string $method): string
    {
        return match ($method) {
            'GET'    => 'Membaca data.',
            'POST'   => 'Membuat / memicu aksi.',
            'PUT', 'PATCH' => 'Memperbarui data.',
            'DELETE' => 'Menghapus data.',
            default  => '',
        };
    }

    // ============================================================
    // FormRequest / rules extraction
    // ============================================================

    private function firstFormRequestParam(ReflectionMethod $method): ?string
    {
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            $class = $type->getName();
            if (is_subclass_of($class, FormRequest::class)) {
                return $class;
            }
        }
        return null;
    }

    /** @return array<string, array<int,string>> */
    private function extractRules(string $formRequestClass): array
    {
        try {
            $ref = new ReflectionClass($formRequestClass);
            if (! $ref->hasMethod('rules')) {
                return [];
            }
            $instance = $ref->newInstanceWithoutConstructor();
            $method   = $ref->getMethod('rules');
            $method->setAccessible(true);
            $rules = $method->invoke($instance);
            if (! is_array($rules)) {
                return [];
            }
            $flat = [];
            foreach ($rules as $field => $ruleList) {
                if (is_string($ruleList)) {
                    $ruleList = explode('|', $ruleList);
                }
                if (! is_array($ruleList)) {
                    continue;
                }
                $flat[$field] = array_map(fn ($r) => is_object($r) ? $this->stringifyRule($r) : (string) $r, $ruleList);
            }
            return $flat;
        } catch (Throwable $e) {
            return [];
        }
    }

    private function stringifyRule(object $rule): string
    {
        if (method_exists($rule, '__toString')) {
            try {
                return (string) $rule;
            } catch (Throwable $e) {
                // fall through
            }
        }
        $class = (new ReflectionClass($rule))->getShortName();
        return "rule:{$class}";
    }

    /** @param array<string,array<int,string>> $rules */
    private function rulesToSchema(array $rules): array
    {
        if ($rules === []) {
            return [];
        }
        $schema = [];
        foreach ($rules as $field => $ruleList) {
            $type      = $this->inferTypeFromRules($ruleList);
            $required  = in_array('required', $ruleList, true);
            $enum      = $this->inferEnumFromRules($ruleList);
            $exists    = $this->inferExistsFromRules($ruleList);
            $constraints = $this->inferConstraintsFromRules($ruleList);

            $node = [
                'type'     => $type,
                'required' => $required,
                'rules'    => $ruleList,
            ];
            if ($enum) {
                $node['enum'] = $enum;
            }
            if ($exists) {
                $node['exists'] = $exists;
            }
            if ($constraints) {
                $node['constraints'] = $constraints;
            }
            $schema[$field] = $node;
        }
        return $schema;
    }

    /** @param array<int,string> $ruleList */
    private function inferTypeFromRules(array $ruleList): string
    {
        foreach ($ruleList as $r) {
            $r = strtolower((string) $r);
            if ($r === 'array') return 'array';
            if ($r === 'boolean' || $r === 'bool') return 'boolean';
            if ($r === 'integer' || $r === 'int') return 'integer';
            if ($r === 'numeric') return 'number';
            if ($r === 'uuid') return 'uuid';
            if ($r === 'email') return 'email';
            if ($r === 'date' || str_starts_with($r, 'date_format')) return 'date';
            if ($r === 'file' || $r === 'image') return 'file';
            if ($r === 'string') return 'string';
        }
        return 'string';
    }

    /** @param array<int,string> $ruleList @return array<int,string>|null */
    private function inferEnumFromRules(array $ruleList): ?array
    {
        foreach ($ruleList as $r) {
            $r = (string) $r;
            if (str_starts_with($r, 'in:')) {
                return explode(',', substr($r, 3));
            }
            if (str_starts_with($r, 'Rule::in:') || str_starts_with($r, 'rule:In')) {
                return null; // Rule::in object — nilai tidak tersedia dari __toString di semua versi
            }
        }
        return null;
    }

    /** @param array<int,string> $ruleList */
    private function inferExistsFromRules(array $ruleList): ?string
    {
        foreach ($ruleList as $r) {
            $r = (string) $r;
            if (str_starts_with($r, 'exists:')) {
                return substr($r, 7);
            }
        }
        return null;
    }

    /** @param array<int,string> $ruleList */
    private function inferConstraintsFromRules(array $ruleList): array
    {
        $out = [];
        foreach ($ruleList as $r) {
            $r = (string) $r;
            if (preg_match('/^min:(\S+)$/', $r, $m)) $out['min'] = is_numeric($m[1]) ? (float) $m[1] : $m[1];
            if (preg_match('/^max:(\S+)$/', $r, $m)) $out['max'] = is_numeric($m[1]) ? (float) $m[1] : $m[1];
            if (preg_match('/^size:(\S+)$/', $r, $m)) $out['size'] = $m[1];
            if (preg_match('/^between:(\S+),(\S+)$/', $r, $m)) $out['between'] = [$m[1], $m[2]];
            if (preg_match('/^regex:(.*)$/', $r, $m)) $out['regex'] = $m[1];
        }
        return $out;
    }

    /** @param array<string,array<int,string>> $rules */
    private function rulesToExample(array $rules): array
    {
        if ($rules === []) {
            return [];
        }
        $example = [];
        foreach ($rules as $field => $ruleList) {
            $type = $this->inferTypeFromRules($ruleList);
            $enum = $this->inferEnumFromRules($ruleList);
            $val  = $this->exampleValueFor($field, $type, $enum, $ruleList);
            $this->setDotPath($example, $field, $val);
        }
        return $example;
    }

    /** @param array<int,string>|null $enum @param array<int,string> $ruleList */
    private function exampleValueFor(string $field, string $type, ?array $enum, array $ruleList): mixed
    {
        if ($enum) {
            return $enum[0];
        }
        $lower = strtolower($field);
        if (str_ends_with($lower, '_id') || $lower === 'id') {
            return in_array('uuid', $ruleList, true) ? '00000000-0000-0000-0000-000000000000' : 1;
        }
        return match ($type) {
            'integer'  => 1,
            'number'   => 0,
            'boolean'  => false,
            'array'    => [],
            'uuid'     => '00000000-0000-0000-0000-000000000000',
            'email'    => 'user@example.com',
            'date'     => now()->toDateString(),
            'file'     => '<binary>',
            default    => 'string',
        };
    }

    private function setDotPath(array &$target, string $path, mixed $value): void
    {
        if (! str_contains($path, '.')) {
            $target[$path] = $value;
            return;
        }
        $keys = explode('.', $path);
        $ref  = &$target;
        foreach ($keys as $i => $key) {
            $isLast = $i === count($keys) - 1;
            if ($key === '*') {
                if (! isset($ref[0])) {
                    $ref[0] = [];
                }
                $ref = &$ref[0];
                continue;
            }
            if ($isLast) {
                $ref[$key] = $value;
            } else {
                if (! isset($ref[$key]) || ! is_array($ref[$key])) {
                    $ref[$key] = [];
                }
                $ref = &$ref[$key];
            }
        }
    }

    /** @param array<string,array<int,string>> $rules @return array<int,string> */
    private function rulesToFlatList(array $rules): array
    {
        $out = [];
        foreach ($rules as $field => $ruleList) {
            $out[] = "{$field}: " . implode('|', $ruleList);
        }
        return $out;
    }

    // ============================================================
    // Params
    // ============================================================

    private function extractPathParams(Route $route): array
    {
        $params = [];
        foreach ($route->parameterNames() as $name) {
            $params[] = [
                'name'     => $name,
                'type'     => 'string',
                'required' => true,
            ];
        }
        return $params;
    }

    private function extractQueryParams(?ReflectionMethod $method): array
    {
        if (! $method) {
            return [];
        }
        $out = [];
        $doc = $method->getDocComment() ?: '';
        if (preg_match_all('/@queryParam\s+(\S+)\s+(\S+)\s*(.*)/', $doc, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $out[] = [
                    'name'        => $row[1],
                    'type'        => $row[2],
                    'description' => trim($row[3]),
                ];
            }
        }
        return $out;
    }

    // ============================================================
    // Middleware -> auth / roles / rate limit
    // ============================================================

    /** @param array<int,string> $middleware */
    private function extractAuth(array $middleware): string
    {
        foreach ($middleware as $m) {
            if (str_starts_with($m, 'auth:')) {
                return $m;
            }
            if ($m === 'auth') return 'auth';
        }
        return 'none';
    }

    /** @param array<int,string> $middleware @return array<int,string> */
    private function extractRoles(array $middleware): array
    {
        $roles = [];
        foreach ($middleware as $m) {
            if (preg_match('/^(role|permission|role_or_permission):(.+)$/', $m, $x)) {
                foreach (preg_split('/[|,]/', $x[2]) as $piece) {
                    $piece = trim($piece);
                    if ($piece !== '') {
                        $roles[] = $piece;
                    }
                }
            }
        }
        return array_values(array_unique($roles));
    }

    /** @param array<int,string> $middleware */
    private function extractRateLimit(array $middleware): ?string
    {
        foreach ($middleware as $m) {
            if (str_starts_with($m, 'throttle:')) {
                return substr($m, 9);
            }
        }
        return null;
    }

    // ============================================================
    // Responses (best-effort)
    // ============================================================

    private function inferSuccessResponse(?ReflectionMethod $method, string $httpMethod): array
    {
        $default = [
            'schema' => [
                'success' => 'boolean',
                'message' => 'string',
                'data'    => 'mixed',
                'meta'    => 'object?',
            ],
            'example' => [
                'success' => true,
                'message' => 'Berhasil.',
                'data'    => $httpMethod === 'GET' ? [] : new \stdClass(),
            ],
        ];
        if (! $method) {
            return $default;
        }
        $doc = $method->getDocComment() ?: '';
        if (preg_match('/@resource\s+(\S+)/', $doc, $m)) {
            $class = $m[1];
            if (class_exists($class) && (is_subclass_of($class, JsonResource::class) || is_subclass_of($class, ResourceCollection::class))) {
                $default['schema']['data'] = $class;
            }
        }
        return $default;
    }

    /** @return array<int,array{status:int,reason:string,body:array}> */
    private function inferErrorResponses(?ReflectionMethod $method, string $auth): array
    {
        $errors = [];
        if ($auth !== 'none') {
            $errors = self::DEFAULT_AUTH_ERRORS;
        }

        $body = $method ? ($this->getMethodBody($method) ?? '') : '';
        if ($body !== '') {
            if (preg_match_all('/abort\s*\(\s*(\d{3})\s*(?:,\s*[\'"]([^\'"]*)[\'"])?/i', $body, $m, PREG_SET_ORDER)) {
                foreach ($m as $row) {
                    $errors[] = [
                        'status' => (int) $row[1],
                        'reason' => $row[2] ?? 'abort()',
                        'body'   => ['message' => $row[2] ?? ''],
                    ];
                }
            }
            if (preg_match_all('/errorResponse\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*(\d{3})/i', $body, $m, PREG_SET_ORDER)) {
                foreach ($m as $row) {
                    $errors[] = [
                        'status' => (int) $row[2],
                        'reason' => $row[1],
                        'body'   => ['success' => false, 'message' => $row[1]],
                    ];
                }
            }
        }

        $errors[] = [
            'status'     => 422,
            'reason'     => 'Validasi payload gagal (aturan FormRequest / validate())',
            'body'       => [
                'message' => 'The given data was invalid.',
                'errors'  => ['nama_field' => ['Pesan validasi per-field (dari FormRequest::messages() atau default Laravel).']],
            ],
            'terjemahan' => 'Data yang dikirim tidak lolos validasi. Cek key `errors` untuk detail per-field.',
        ];
        $errors[] = [
            'status'     => 500,
            'reason'     => 'Kesalahan server (exception tak tertangani)',
            'body'       => ['message' => 'Server Error'],
            'terjemahan' => 'Terjadi kesalahan tak terduga di server. Cek log Laravel & Sentry untuk trace.',
        ];

        $seen = [];
        $out  = [];
        foreach ($errors as $e) {
            $key = $e['status'] . '|' . $e['reason'];
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $e;
        }
        return $out;
    }

    /** @param array<int,array> $errors @return array<int,int> */
    private function inferStatusCodes(string $method, array $errors): array
    {
        $success = match ($method) {
            'POST'   => 201,
            'DELETE' => 204,
            default  => 200,
        };
        $codes = [$success];
        foreach ($errors as $e) {
            $codes[] = (int) $e['status'];
        }
        return array_values(array_unique($codes));
    }

    // ============================================================
    // Side effects
    // ============================================================

    /** @return array<int,string> */
    private function inferSideEffects(?ReflectionMethod $method): array
    {
        if (! $method) return [];
        $body = $this->getMethodBody($method) ?? '';
        if ($body === '') return [];

        $out = [];
        if (preg_match_all('/Event::dispatch\s*\(\s*(?:new\s+)?([A-Za-z0-9_\\\\]+)/', $body, $m)) {
            foreach ($m[1] as $c) $out[] = "Event: {$c}";
        }
        if (preg_match_all('/\bevent\s*\(\s*new\s+([A-Za-z0-9_\\\\]+)/', $body, $m)) {
            foreach ($m[1] as $c) $out[] = "Event: {$c}";
        }
        if (preg_match_all('/([A-Za-z0-9_\\\\]+)::dispatch(Sync|AfterResponse)?\s*\(/', $body, $m)) {
            foreach ($m[1] as $c) {
                if (in_array($c, ['Event', 'Bus', 'Log', 'DB', 'Cache'], true)) continue;
                $out[] = "Job/Event: {$c}";
            }
        }
        if (preg_match_all('/Notification::send\s*\([^,]+,\s*(?:new\s+)?([A-Za-z0-9_\\\\]+)/', $body, $m)) {
            foreach ($m[1] as $c) $out[] = "Notification: {$c}";
        }
        return array_values(array_unique($out));
    }

    private function getMethodBody(ReflectionMethod $method): ?string
    {
        $file = $method->getFileName();
        if (! $file || ! is_readable($file)) {
            return null;
        }
        $start = $method->getStartLine();
        $end   = $method->getEndLine();
        if (! $start || ! $end) {
            return null;
        }
        $lines = @file($file);
        if (! $lines) return null;
        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }

    // ============================================================
    // Related page (heuristik dari nama route)
    // ============================================================

    // ============================================================
    // Related endpoints (same-controller grouping)
    // ============================================================

    /** @param array<int,array> $endpoints */
    private function attachRelatedEndpoints(array &$endpoints): void
    {
        // Index endpoint id by controller
        $byController = [];
        foreach ($endpoints as $i => $e) {
            $ctrl = $e['controller'];
            if (! $ctrl) continue;
            $byController[$ctrl][] = $i;
        }
        foreach ($endpoints as $i => &$e) {
            $ctrl = $e['controller'];
            if (! $ctrl || ! isset($byController[$ctrl])) continue;
            $others = [];
            foreach ($byController[$ctrl] as $j) {
                if ($j === $i) continue;
                $others[] = [
                    'id'     => $endpoints[$j]['id'],
                    'method' => $endpoints[$j]['method'],
                    'path'   => $endpoints[$j]['path'],
                    'summary'=> $endpoints[$j]['summary'],
                ];
                if (count($others) >= 8) break;
            }
            $e['related_endpoints'] = $others;
        }
    }

    // ============================================================
    // Field description enrichment
    // ============================================================

    /** @param array<string,array> $schema */
    private function enrichSchemaDescriptions(array $schema): array
    {
        foreach ($schema as $field => &$spec) {
            $spec['description'] = $this->fields->describe($field, $spec['type'] ?? 'string');
        }
        return $schema;
    }

    /** @param array<int,array> $params */
    private function enrichPathParamDescriptions(array $params): array
    {
        foreach ($params as &$p) {
            $p['description'] = $this->fields->describe($p['name'], $p['type'] ?? 'string');
        }
        return $params;
    }

    /** @param array<int,array{name:string,type:string,column:?string,scope:?string}> $filters */
    private function enrichFilterDescriptions(array $filters, string $moduleSlug = ''): array
    {
        foreach ($filters as &$f) {
            $desc = $this->fields->describe($f['name'], 'filter');
            $enumValues = $this->enums->forFilter($moduleSlug, $f['name']);
            $exampleValue = $enumValues[0] ?? 'value';
            $typeNote = match ($f['type']) {
                'exact'    => "Cocok persis. Pemakaian: `?filter[{$f['name']}]={$exampleValue}`.",
                'partial'  => "Cocok sebagian (LIKE %value%). Pemakaian: `?filter[{$f['name']}]=keyword`.",
                'scope'    => "Delegasi ke query scope `{$f['scope']}` di Model. Nilai diteruskan sebagai argumen scope.",
                'callback' => "Diproses via callback custom di Repository. Nilai valid tergantung logika — lihat kolom enum bila ada.",
                'trashed'  => "Filter untuk soft-deleted records (with/only).",
                default    => "Filter tipe {$f['type']}.",
            };
            $f['description']   = "{$desc} {$typeNote}";
            $f['usage_example'] = "?filter[{$f['name']}]={$exampleValue}";
            if ($enumValues) {
                $f['enum'] = $enumValues;
            }
        }
        return $filters;
    }

    private function guessRelatedPage(?string $name): ?string
    {
        if (! $name) return null;
        $map = [
            'sales.'         => '/dashboard/pesanan',
            'sales.orders.'  => '/dashboard/pesanan',
            'sales.returns.' => '/dashboard/pengaturan/retur',
            'sales.internal-stores.' => '/dashboard/toko-internal',
            'outbound.pick'  => '/dashboard/proses-pesanan/picking',
            'outbound.pack'  => '/dashboard/proses-pesanan/packing',
            'outbound.ship'  => '/dashboard/proses-pesanan/shipping',
            'outbound.deliver' => '/dashboard/proses-pesanan/delivered',
            'inbound.'       => '/dashboard/barang-masuk',
            'inventory.transfer.out' => '/dashboard/barang-keluar',
            'inventory.transfer.'    => '/dashboard/transaksi-stok',
            'inventory.'     => '/dashboard/transaksi-stok',
            'product.'       => '/dashboard/produk',
            'channel.'       => '/dashboard/integrasi-channel',
            'purchase.'      => '/dashboard/transaksi-pembelian',
            'supplier.'      => '/dashboard/kontak-pemasok',
            'contact.customer' => '/dashboard/kontak-pelanggan',
            'notification.'  => '/dashboard/notifikasi',
            'report.'        => '/dashboard/laporan',
            'auth.users.'    => '/dashboard/pengaturan/pengguna',
            'auth.roles.'    => '/dashboard/pengaturan/peran',
            'auth.profile.'  => '/dashboard/profil-saya',
            'warehouse.locations.' => '/dashboard/lokasi',
        ];
        foreach ($map as $prefix => $page) {
            if (str_contains($name, $prefix)) {
                return $page;
            }
        }
        return null;
    }
}
