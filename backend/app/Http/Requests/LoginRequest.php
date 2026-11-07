<?php

namespace App\Http\Requests;

use App\Support\StudentNumber;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Students sign in with their student number (#56); "a26-0001" or
     * "A260001" is read as A26-0001. Other usernames are left as typed.
     */
    protected function prepareForValidation(): void
    {
        $username = $this->input('username');
        if (is_string($username) && preg_match('/^\s*[A-Za-z]\d{2}-?\d{4}\s*$/', $username)) {
            $this->merge(['username' => StudentNumber::normalize($username)]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }
}
