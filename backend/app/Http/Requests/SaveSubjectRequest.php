<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update a subject. The same code may appear under different titles
 * (e.g. BSE's GE subjects differ from BSTM/BSHM's), so uniqueness is on the
 * code + title pair, matching the subjects_code_title_unique index.
 */
class SaveSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('subjects', 'code')
                    ->where('title', $this->input('title'))
                    ->ignore($this->route('id')),
            ],
            'title'       => ['required', 'string', 'max:150'],
            'units'       => ['required', 'integer', 'min:0', 'max:12'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'A subject with this code and title already exists.',
        ];
    }
}
