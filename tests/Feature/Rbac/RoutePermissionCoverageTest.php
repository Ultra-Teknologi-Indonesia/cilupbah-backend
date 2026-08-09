<?php

namespace Tests\Feature\Rbac;

use Illuminate\Support\Facades\Route;
use Modules\Auth\Support\PermissionCatalog;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Tests\TestCase;

class RoutePermissionCoverageTest extends TestCase
{

    private const VENDOR_PREFIXES = [
        'horizon', 'telescope', '_ignition', 'sanctum',
        'api/documentation', 'docs', 'oauth', 'up', 'storage',
    ];

    private const SELF_SERVICE = [
        'api/v1/auth/logout',
        'api/v1/auth/refresh',
        'api/v1/auth/unlock',
        'api/v1/bantuan/api-docs',
        'api/v1/bantuan/api-docs/{slug}',
        'api/v1/dashboard/task-counts',
        'api/v1/device-tokens',
        'api/v1/media/upload',
        'api/v1/media/upload/{uuid}',
        'api/v1/notifications',
        'api/v1/notifications/read-all',
        'api/v1/notifications/unread-count',
        'api/v1/notifications/{notification}',
        'api/v1/notifications/{notification}/read',
        'api/v1/profile',
        'api/v1/profile/avatar',
        'api/v1/profile/histories',
        'api/v1/profile/login-histories',
        'api/v1/profile/password',
        'api/v1/profile/sessions',
        'api/v1/profile/sessions/revoke-others',
        'api/v1/profile/sessions/{id}/revoke',

        'api/v1/reports/exports/{export}',
        'api/v1/reports/exports/{export}/download',

        'api/v1/regions/countries',
        'api/v1/regions/provinces',
        'api/v1/regions/cities/{province_id}',
        'api/v1/regions/districts/{city_id}',
        'api/v1/regions/villages/{district_id}',

        'issues/{issue}',
    ];

    private function isVendor(\Illuminate\Routing\Route $route): bool
    {
        $uri = $route->uri();
        foreach (self::VENDOR_PREFIXES as $prefix) {
            if ($uri === $prefix || str_starts_with($uri, $prefix.'/')) {
                return true;
            }
        }

        $action = $route->getActionName();

        return str_contains($action, 'Horizon')
            || str_contains($action, 'L5Swagger')
            || str_contains($action, 'Ignition');
    }

    private function permissionTokens(\Illuminate\Routing\Route $route): array
    {
        $tokens = [];
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }
            if (! str_starts_with($middleware, 'role_or_permission:')
                && ! str_starts_with($middleware, RoleOrPermissionMiddleware::class.':')) {
                continue;
            }
            $args = explode(':', $middleware, 2)[1] ?? '';
            foreach (explode('|', explode(',', $args)[0]) as $token) {
                $tokens[] = trim($token);
            }
        }

        return $tokens;
    }

    public function test_setiap_permission_yang_dirujuk_route_ada_di_katalog(): void
    {
        $catalog = PermissionCatalog::allPermissionNames();
        $roles = ['owner', 'admin'];
        $orphans = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($this->permissionTokens($route) as $token) {
                if (in_array($token, $roles, true) || in_array($token, $catalog, true)) {
                    continue;
                }
                $orphans[$token][] = $route->uri();
            }
        }

        $this->assertSame([], $orphans, sprintf(
            "Permission berikut dipakai di middleware tapi tidak ada di config/rbac.php.\n".
            "Spatie mengembalikan false diam-diam untuk ability tak dikenal, jadi endpoint ini\n".
            "efektif terkunci untuk role `owner` saja (lewat Gate::before) — admin pun tertolak.\n%s",
            json_encode($orphans, JSON_PRETTY_PRINT)
        ));
    }

    public function test_route_bisnis_terautentikasi_wajib_punya_permission_gate(): void
    {
        $ungated = [];

        foreach (Route::getRoutes() as $route) {
            if ($this->isVendor($route)) {
                continue;
            }
            if ($this->permissionTokens($route) !== []) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $authenticated = false;
            foreach ($middleware as $m) {
                if (is_string($m) && (str_starts_with($m, 'auth') || str_contains($m, 'Authenticate'))) {
                    $authenticated = true;
                    break;
                }
            }
            if (! $authenticated) {
                continue; 
            }

            if (in_array($route->uri(), self::SELF_SERVICE, true)) {
                continue;
            }

            $ungated[] = $route->methods()[0].' '.$route->uri();
        }

        sort($ungated);

        $this->assertSame([], $ungated, sprintf(
            "Route bisnis berikut butuh login tapi TIDAK punya permission gate.\n".
            "Middleware route adalah satu-satunya lapis otorisasi di codebase ini, jadi\n".
            "route ini bisa dipanggil user dengan role apa pun.\n".
            "Pasang `role_or_permission:owner|<permission>`, atau kalau memang self-scoped\n".
            "tambahkan ke SELF_SERVICE di test ini beserta alasannya.\n%s",
            json_encode($ungated, JSON_PRETTY_PRINT)
        ));
    }

    public function test_tidak_ada_route_yang_menumpuk_lebih_dari_satu_permission(): void
    {
        $stacked = [];

        foreach (Route::getRoutes() as $route) {
            $groups = 0;
            foreach ($route->gatherMiddleware() as $m) {
                if (is_string($m)
                    && (str_starts_with($m, 'role_or_permission:')
                        || str_starts_with($m, RoleOrPermissionMiddleware::class.':'))) {
                    $groups++;
                }
            }
            if ($groups > 1) {
                $stacked[] = $route->methods()[0].' '.$route->uri();
            }
        }

        $this->assertSame([], $stacked, sprintf(
            "Route berikut punya lebih dari satu middleware role_or_permission.\n".
            "Middleware ditumpuk secara AND, jadi user harus memiliki SEMUA permission —\n".
            "biasanya ini akibat menambah gate di route yang sudah berada dalam grup ber-gate.\n%s",
            json_encode($stacked, JSON_PRETTY_PRINT)
        ));
    }

    public function test_setiap_role_default_resolve_ke_permission_yang_valid(): void
    {
        $catalog = PermissionCatalog::allPermissionNames();
        $invalid = [];

        foreach (config('rbac.defaults', []) as $role => $tokens) {
            foreach (PermissionCatalog::resolveGrants($tokens) as $permission) {
                if (! in_array($permission, $catalog, true)) {
                    $invalid[$role][] = $permission;
                }
            }
        }

        $this->assertSame([], $invalid, sprintf(
            "Blok `defaults` menghasilkan permission yang tidak ada di katalog:\n%s",
            json_encode($invalid, JSON_PRETTY_PRINT)
        ));
    }
}
