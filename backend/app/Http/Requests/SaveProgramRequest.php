<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code'        => ['required', 'string', 'max:20', Rule::unique('programs', 'code')->ignore($this->route('id'))],
            'name'        => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'A program with this code already exists.',
        ];
    }
}
