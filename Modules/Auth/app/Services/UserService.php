<?php

namespace Modules\Auth\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class UserService
{
    /**
     * Create a new user with assigned role.
     */
    public function createUser(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'nik' => $data['nik'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
            ]);

            $user->assignRole($data['role']);

            return $user;
        });
    }

    /**
     * Update an existing user.
     */
    public function updateUser(string $id, array $data): User
    {
        return DB::transaction(function () use ($id, $data) {
            $user = User::findOrFail($id);

            $user->name = $data['name'];
            $user->email = $data['email'];
            
            if (isset($data['password']) && !empty($data['password'])) {
                $user->password = Hash::make($data['password']);
            }

            $user->nik = $data['nik'] ?? null;
            $user->warehouse_id = $data['warehouse_id'] ?? null;
            
            $user->save();

            $user->syncRoles([$data['role']]);

            return $user;
        });
    }
}
