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

    public const CODE_TAKEN = 'A program with this code already exists.';

    /** Also used when a program is created with its curriculum (#70). */
    public static function fieldRules(mixed $ignoreId = null): array
    {
        return [
            'code'        => ['required', 'string', 'max:20', Rule::unique('programs', 'code')->ignore($ignoreId)],
            'name'        => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function rules(): array
    {
        return self::fieldRules($this->route('id'));
    }

    public function messages(): array
    {
        return [
            'code.unique' => self::CODE_TAKEN,
        ];
    }
}
