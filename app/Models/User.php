<?php

namespace App\Models;

use App\Services\UploadService;
use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;
use Modules\Warehouse\Models\Location;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, HasUuid7, Notifiable {
        hasPermissionTo as protected hasPermissionToViaSpatie;
    }

    protected $keyType = 'string';

    public $incrementing = false;

    protected ?array $allowedLocationIdsCache = null;

    protected bool $allowedLocationIdsResolved = false;

    protected ?array $deniedPermissionNamesCache = null;

    protected $fillable = [
        'name',
        'email',
        'password',
        'nik',
        'phone',
        'warehouse_id',
        'avatar_media_id',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }

    public function getAvatarUrlAttribute(): ?string
    {
        if (! $this->avatar_media_id) {
            return null;
        }

        return app(UploadService::class)->findByUuid($this->avatar_media_id)?->getUrl();
    }

    protected array $transientProfileContext = [];

    public function setProfileContext(string $key, mixed $value): void
    {
        $this->transientProfileContext[$key] = $value;
    }

    public function getLocationTreeAttribute(): mixed
    {
        return $this->transientProfileContext['location_tree'] ?? null;
    }

    public function getAllPermissionNamesAttribute(): mixed
    {
        return $this->transientProfileContext['all_permission_names'] ?? null;
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'user_locations', 'user_id', 'location_id')
            ->withTimestamps();
    }

    public function permissionDenials(): HasMany
    {
        return $this->hasMany(UserPermissionDenial::class);
    }

    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        $resolvedPermission = $this->filterPermission($permission, $guardName);

        if ($this->hasDeniedPermissionName($resolvedPermission->name)) {
            return false;
        }

        return $this->hasPermissionToViaSpatie($resolvedPermission, $guardName);
    }

    public function effectivePermissionNames(): Collection
    {
        $this->loadMissing([
            'roles.permissions',
            'permissions',
            'permissionDenials.permission',
        ]);

        $denied = array_flip($this->deniedPermissionNames());

        return $this->getAllPermissions()
            ->pluck('name')
            ->reject(static fn (string $name): bool => isset($denied[$name]))
            ->unique()
            ->sort()
            ->values();
    }

    public function deniedPermissionNames(): array
    {
        if ($this->deniedPermissionNamesCache !== null) {
            return $this->deniedPermissionNamesCache;
        }

        $this->loadMissing('permissionDenials.permission');

        return $this->deniedPermissionNamesCache = $this->permissionDenials
            ->pluck('permission.name')
            ->filter()
            ->values()
            ->all();
    }

    public function hasDeniedPermissionName(string $permissionName): bool
    {
        return in_array($permissionName, $this->deniedPermissionNames(), true);
    }

    public function forgetDeniedPermissionNamesCache(): void
    {
        $this->deniedPermissionNamesCache = null;
        $this->unsetRelation('permissionDenials');
    }

    public function allowedLocationIds(): ?array
    {
        if ($this->allowedLocationIdsResolved) {
            return $this->allowedLocationIdsCache;
        }

        $this->allowedLocationIdsResolved = true;

        if ($this->hasRole('owner')) {
            return $this->allowedLocationIdsCache = null;
        }

        $ids = $this->locations()->pluck('locations.id')->all();

        return $this->allowedLocationIdsCache = ($ids === [] ? null : $ids);
    }

    public function flushAllowedLocationIds(): void
    {
        $this->allowedLocationIdsResolved = false;
        $this->allowedLocationIdsCache = null;
    }

    public function syncLocations(array $locationIds): void
    {
        $this->locations()->detach();

        $payload = [];
        foreach (array_values(array_unique($locationIds)) as $id) {
            $payload[$id] = ['id' => Uuid::uuid7()->toString()];
        }

        if ($payload !== []) {
            $this->locations()->attach($payload);
        }

        $this->flushAllowedLocationIds();
    }
}
