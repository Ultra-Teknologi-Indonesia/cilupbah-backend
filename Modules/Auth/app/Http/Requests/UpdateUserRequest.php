<?php

namespace Modules\Auth\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('id');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $userId],
            'password' => [
                'nullable',
                'string',
                'min:8',
                'max:30',
                'regex:/[a-z]/',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
                'regex:/[@$!%*#?&]/',
                'confirmed',
            ],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [
                'required',
                'string',
                'exists:roles,name',
                function ($attribute, $value, $fail) use ($userId) {
                    if ($value === 'owner') {
                        $user = User::find($userId);
                        if (! $user || ! $user->hasRole('owner')) {
                            $fail('Role owner tidak dapat diberikan atau dipindahkan ke pengguna lain.');
                        }
                    }
                },
            ],
            'nik' => ['nullable', 'string', 'max:255'],
            'warehouse_id' => ['nullable', 'bail', 'uuid', 'exists:locations,id'],
            'avatar_media_id' => ['nullable', 'bail', 'uuid', 'exists:media,uuid'],
        ];
    }

    protected function passedValidation(): void
    {
        $user = User::find($this->route('id'));
        if ($user && $user->hasRole('owner')) {
            $roles = $this->input('roles', []);
            if (! in_array('owner', $roles)) {
                $roles[] = 'owner';
                $this->merge(['roles' => $roles]);
            }
        }
    }

    public function messages(): array
    {
        return [
            'password.regex' => 'Password harus mengandung setidaknya satu huruf besar, satu huruf kecil, satu angka, dan satu karakter khusus (@$!%*#?&).',
        ];
    }
}
