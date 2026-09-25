<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('id') ?? $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'role' => ['required', 'string', 'in:student,staff,admin'],
            'department' => ['nullable', 'string', 'max:100'],
            'status' => ['required', 'string', 'in:active,inactive'],

            // Optional administrative password reset. The system is deployed on
            // an isolated LAN with no mail server, so a self-service "forgot
            // password" e-mail is not possible; the administrator resetting the
            // credential in person is the recovery path (§3.9.1).
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'confirmed'],
        ];
    }
}
