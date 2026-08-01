<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Warehouse\Models\Location;
use Spatie\Permission\Traits\HasRoles;
use App\Services\UploadService;
use App\Traits\HasUuid7;

class User extends Authenticatable
{

    use HasApiTokens, HasFactory, Notifiable, HasRoles, HasUuid7;

    protected $keyType = 'string';
    public $incrementing = false;

    protected ?array $allowedLocationIdsCache = null;
    protected bool $allowedLocationIdsResolved = false;

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

    /**
     * Data profil transien (location_tree, all_permission_names) yang di-attach
     * UserService untuk ProfileResource. Disimpan DI LUAR attribute bag & relations
     * agar tidak ikut di-persist saat save()/update() (kolomnya tidak ada di tabel
     * users → error 500) dan tidak dianggap relasi saat refresh().
     */
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
            $payload[$id] = ['id' => \Ramsey\Uuid\Uuid::uuid7()->toString()];
        }

        if ($payload !== []) {
            $this->locations()->attach($payload);
        }

        $this->flushAllowedLocationIds();
    }
}
