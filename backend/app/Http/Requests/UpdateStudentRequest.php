<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Rules aligned with students table schema. Unique rules ignore current student.
     */
    public function rules(): array
    {
        $studentId = $this->route('id');
        $student = \App\Models\Student::find($studentId);
        $userId = $student?->user_id;

        return [
            // The number is the login username: it changes only through
            // Change Student Number, which needs a reason and is audited (#56).
            'student_number' => [
                'required',
                'string',
                'max:20',
                function (string $attribute, mixed $value, \Closure $fail) use ($student) {
                    if ($student && $value !== $student->student_number) {
                        $fail('Use Change Student Number to change a student number; a reason is required.');
                    }
                },
            ],
            'first_name' => ['required', 'string', 'max:50'],
            'middle_name' => ['nullable', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:50'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'email' => [
                'required',
                'string',
                'email',
                'max:100',
                Rule::unique('students', 'email')->ignore($studentId, 'student_id'),
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'contact_number' => ['nullable', 'string', 'max:15'],
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
            'enrollment_date' => ['required', 'date', 'before_or_equal:today'],
            'graduation_date' => ['nullable', 'date', 'after_or_equal:enrollment_date'],

        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'This email is already registered.',
        ];
    }
}
