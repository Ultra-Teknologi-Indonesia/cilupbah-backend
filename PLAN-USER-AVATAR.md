# PLAN — Integrasi User Avatar (memakai endpoint media terpusat)

## 1. Konsep & keputusan

Avatar mengikuti pola yang sudah disepakati: **tabel hanya menyimpan referensi**, file dikelola endpoint media terpusat `POST /api/v1/media/upload`.

- Kolom baru `users.avatar_media_id` (UUID, nullable) → menyimpan **media UUID** (Spatie `media.uuid`, yang **stabil saat replace**).
- **Kenapa UUID, bukan URL:** saat file di-*replace* lewat `PUT /media/upload/{uuid}`, baris media baru dibuat (path & URL berubah) tetapi **UUID dipertahankan** → `avatar_media_id` user tetap valid otomatis, URL diambil segar. Menyimpan URL akan basi setelah replace.
- Alur frontend:
  1. `POST /media/upload` (file) → `{ uuid, url }`
  2. set avatar: kirim `uuid` itu ke endpoint avatar / user update.
  3. render: response user mengembalikan `avatar_url` (di-resolve dari `avatar_media_id`).

## 2. Perubahan database

Migration `add_avatar_media_id_to_users_table`:
```php
$table->uuid('avatar_media_id')->nullable()->after('warehouse_id');
// tanpa FK keras ke media.uuid (media bisa terhapus; null-kan via app-logic)
```

## 3. Endpoint

### 3.1 Self-service (user mengatur avatarnya sendiri)
`PUT /api/v1/profile/avatar` (auth:sanctum)
- Body: `{ "media_uuid": "<uuid>" }` untuk set, atau `{ "media_uuid": null }` untuk lepas.
- Validasi (FormRequest `UpdateAvatarRequest`): `media_uuid` → `nullable|bail|uuid|exists:media,uuid` (bail+uuid mencegah 500 cast uuid Postgres).
- Controller `AuthController@updateAvatar` thin → `UserService@setAvatar($user, $uuid)`.
- Response: `ProfileResource` (sudah memuat `avatar_url`).

### 3.2 Admin (create/update user)
- Tambah `avatar_media_id` ke `StoreUserRequest` & `UpdateUserRequest`: `nullable|bail|uuid|exists:media,uuid`.
- `UserService@createUser/updateUser` menyimpan `avatar_media_id` bila ada.

> Upload file TETAP lewat `/media/upload` (tidak ada logika upload baru di avatar). Avatar hanya menautkan referensi.

## 4. Resource

`ProfileResource` (dipakai profile, list, create, update) tambah:
```php
'avatar_media_id' => $this->avatar_media_id,
'avatar_url'      => $this->avatar_url,   // accessor
```

## 5. Resolusi URL (accessor + catatan N+1)

Accessor di `User`:
```php
public function getAvatarUrlAttribute(): ?string
{
    if (! $this->avatar_media_id) return null;
    return app(\App\Services\UploadService::class)->findByUuid($this->avatar_media_id)?->getUrl();
}
```
- Untuk **detail/profile** (1 user) → 1 query, oke.
- Untuk **list user** (`GET /users`, paginate 10) → potensi N+1. **Optimasi (opsional, dicatat):** di repository list, prefetch `Media::whereIn('uuid', $ids)` lalu map; untuk implementasi pertama accessor sudah cukup (≤10 query/halaman).

## 6. Cleanup (hindari dua mekanisme)

User sebelumnya sempat saya beri koleksi Spatie `avatar` langsung (`implements HasMedia` + `HasUploadableMedia`). Karena avatar kini **berbasis referensi** ke pool terpusat, mekanisme langsung itu redundan:
- Hapus `implements HasMedia`, `use HasUploadableMedia`, dan `registerMediaCollections()` (avatar) dari `User`.
- `MediaServiceTest` (yang menguji `MediaService` generik via User avatar) **dialihkan ke model `Upload`** (model pembawa media yang sebenarnya) — cakupan tes tetap sama.

## 7. Tests

`Modules/Auth/tests/Feature/UserAvatarApiTest.php`:
1. `set_avatar_via_profile` — upload ke `/media/upload` → `PUT /profile/avatar {media_uuid}` → 200, `users.avatar_media_id` terisi, `avatar_url` muncul di `/profile`.
2. `replace_file_keeps_avatar_link` — `PUT /media/upload/{uuid}` (ganti file) → `avatar_media_id` tetap, `avatar_url` berubah ke file baru.
3. `remove_avatar` — `PUT /profile/avatar {media_uuid:null}` → `avatar_media_id` null, `avatar_url` null.
4. `invalid_media_uuid_returns_422` — uuid acak (tak ada di media) → 422.
5. `non_uuid_media_returns_422_not_500` — `media_uuid:"abc"` → 422 (bukan 500).
6. `admin_can_set_avatar_on_user_update` — `PUT /users/{id}` dengan `avatar_media_id` → tersimpan.

Target: semua hijau + `route:cache` sukses + tidak ada regresi suite Auth/Media/Product.

## 8. No-500 & integrasi
| Aspek | Jaminan |
|---|---|
| `media_uuid`/`avatar_media_id` non-UUID | `bail+uuid` → 422 (tanpa query cast uuid) |
| media yang dirujuk sudah dihapus | accessor balikan `null` (findByUuid → null) |
| Tidak bentrok modul lain | hanya nambah kolom nullable + endpoint avatar; pakai infra media yang sudah ada |

## 9. Definition of Done
- [ ] Migration `avatar_media_id` jalan
- [ ] `PUT /profile/avatar` set/replace/remove
- [ ] `avatar_media_id` di Store/UpdateUserRequest + UserService
- [ ] `ProfileResource`: `avatar_media_id` + `avatar_url`
- [ ] Cleanup User media collection + repoint MediaServiceTest
- [ ] Test avatar hijau, no-500, route:cache OK
