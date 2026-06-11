<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        // Get the user ID from the route parameter
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
                'confirmed'
            ],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [
                'required', 
                'string', 
                'exists:roles,name',
                function ($attribute, $value, $fail) use ($userId) {
                    // Prevent assigning 'owner' to someone who isn't already the owner
                    if ($value === 'owner') {
                        $user = \App\Models\User::find($userId);
                        if (!$user || !$user->hasRole('owner')) {
                            $fail('Role owner tidak dapat diberikan atau dipindahkan ke pengguna lain.');
                        }
                    }
                }
            ],
            'nik' => ['nullable', 'string', 'max:255'],
            // bail+uuid agar warehouse_id non-UUID gagal di format (422), tidak lanjut ke
            // query exists yang akan memicu error cast uuid Postgres (500).
            'warehouse_id' => ['nullable', 'bail', 'uuid', 'exists:locations,id'],
            'avatar_media_id' => ['nullable', 'bail', 'uuid', 'exists:media,uuid'],
        ];
    }

    protected function passedValidation()
    {
        // If the user being edited IS the owner, force their roles to include 'owner'
        // so they cannot accidentally remove their own owner role.
        $user = \App\Models\User::find($this->route('id'));
        if ($user && $user->hasRole('owner')) {
            $roles = $this->input('roles', []);
            if (!in_array('owner', $roles)) {
                $roles[] = 'owner';
                $this->merge(['roles' => $roles]);
            }
        }
    }

    /**
     * Custom error messages
     */
    public function messages(): array
    {
        return [
            'password.regex' => 'Password harus mengandung setidaknya satu huruf besar, satu huruf kecil, satu angka, dan satu karakter khusus (@$!%*#?&).'
        ];
    }
}
