<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{

    protected bool $dropTypes = true;

    /**
     * User test yang lolos semua permission gate.
     *
     * Sebagian besar test di suite ini menguji perilaku bisnis, bukan otorisasi,
     * tapi menembak endpoint yang ter-gate `role_or_permission`. Sebelum ada
     * helper ini, test-test tersebut memakai user polos tanpa role sehingga
     * dapat 403 dan gagal karena alasan yang tidak ada hubungannya dengan
     * apa yang diuji.
     *
     * Dipakai role `owner` karena `AppServiceProvider::boot()` memasang
     * `Gate::before` yang meloloskan owner untuk semua ability — jadi cukup
     * satu baris role, tidak perlu menanam ratusan baris permission per test.
     *
     * JANGAN pakai helper ini di test yang memang menguji otorisasi
     * (mis. memastikan user tanpa izin dapat 403). Di sana pakai
     * `User::factory()->create()` langsung.
     */
    protected function createPrivilegedUser(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        $role = Role::firstOrCreate(
            ['name' => 'owner', 'guard_name' => 'web'],
        );

        $user->assignRole($role);

        return $user;
    }
}
