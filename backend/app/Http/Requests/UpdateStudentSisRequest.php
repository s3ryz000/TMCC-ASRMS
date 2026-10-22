<?php

namespace App\Http\Requests;

use App\Support\ContactRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStudentSisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('contact_number')) {
            $this->merge(['contact_number' => ContactRules::normalizeMobile($this->input('contact_number'))]);
        }
    }

    public function rules(): array
    {
        return [
            'contact_number' => ContactRules::mobile(),
            'address' => ['nullable', 'string', 'max:150'],
            'place_of_birth' => ['nullable', 'string', 'max:120'],
            'sex' => ['nullable', 'string', 'in:M,F'],
            'guardian_name' => ['nullable', 'string', 'max:120'],
            'citizenship' => ['nullable', 'string', 'max:60'],

            'elementary_school' => ['nullable', 'string', 'max:150'],
            'elementary_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'high_school' => ['nullable', 'string', 'max:150'],
            'high_school_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'previous_school' => ['nullable', 'string', 'max:150'],
            'previous_course' => ['nullable', 'string', 'max:150'],

            'supporting_document' => [
                'nullable',
                'file',
                'mimes:png,jpg,jpeg,pdf,docx',
                'max:5120', // 5 MB max
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'contact_number.regex' => ContactRules::MOBILE_MESSAGE,
        ];
    }
}

