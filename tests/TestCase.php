<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{

    protected bool $dropTypes = true;

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
