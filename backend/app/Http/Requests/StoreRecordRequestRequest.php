<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRecordRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'record_type' => ['required', 'string', 'in:transcript,certificate_of_grades,copy_of_grades,deans_list_certificate,presidents_list_certificate,latin_honor_certificate'],
            'academic_year' => ['nullable', 'string', 'max:20'],
            'semester' => ['nullable', 'string', 'max:20'],
            'award_name' => ['nullable', 'string', 'max:100'],
            // The registrar needs to know what the document is for (#93); the
            // pending list and the approval slip show it.
            'purpose' => ['required', 'string', 'max:255'],
            'copies' => ['nullable', 'integer', 'min:1', 'max:10'],
        ];
    }

    public function messages(): array
    {
        return [
            'record_type.in' => 'Invalid document type requested.',
            'purpose.required' => 'Enter the purpose of the request, e.g. employment or scholarship.',
            'purpose.max' => 'The purpose may not be longer than 255 characters.',
            'copies.min' => 'Request at least 1 copy.',
            'copies.max' => 'You can request at most 10 copies.',
        ];
    }
}
