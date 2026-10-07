<?php

namespace App\Http\Requests;

use App\Rules\StrongPassword;
use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required', 'string', 'confirmed',
                new StrongPassword($this->stringInput('username'), $this->stringInput('email')),
            ],
            'role' => ['required', 'string', 'in:student,staff,admin'],
            'department' => ['nullable', 'string', 'max:100'],
            
        ];
    }

    private function stringInput(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) ? $value : null;
    }

    public function messages(): array
    {
        return [
            'username.unique' => 'This username is already taken.',
            'email.unique' => 'This email is already registered.',
        ];
    }
}
