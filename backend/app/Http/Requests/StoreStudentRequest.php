<?php

namespace App\Http\Requests;

use App\Support\StudentNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The student number is the enrollment year's 2 digits plus 4 the
     * registrar types (#56). Either the full 6 digits or just the 4-digit part
     * may be sent; the part gets the prefix from enrollment_date.
     */
    protected function prepareForValidation(): void
    {
        $number = $this->input('student_number');
        if (! is_string($number)) {
            return;
        }

        $number = preg_replace('/\s+/', '', $number);
        $prefix = StudentNumber::yearPrefix($this->input('enrollment_date'));
        if (preg_match('/^\d{4}$/', $number) && $prefix !== null) {
            $number = StudentNumber::compose($prefix, $number);
        }

        $this->merge(['student_number' => $number]);
    }

    /**
     * Rules aligned with students table schema and thesis requirements.
     *
     */
    public function rules(): array
    {
        return [
            // Uniqueness is checked by the controller, which answers a taken
            // number with the holder's details (#56) and guards the race.
            'student_number' => ['required', 'string', 'regex:' . StudentNumber::PATTERN, $this->matchesEnrollmentYear(...)],
            'first_name' => ['required', 'string', 'max:50'],
            'middle_name' => ['nullable', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:50'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'email' => ['required', 'string', 'email', 'max:100', 'unique:students,email', 'unique:users,email'],
            'contact_number' => ['nullable', 'string', 'max:15'],
            'address' => ['nullable', 'string', 'max:150'],
            'place_of_birth' => ['nullable', 'string', 'max:120'],
            'sex' => ['required', 'string', 'in:M,F'],
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

            'program_id' => ['required', 'exists:programs,id'],
            'subject_ids' => ['nullable', 'array'],
            'subject_ids.*' => ['integer', 'exists:subjects,id'],
            ...ArchiveLocationRequest::fieldRules(),
        ];
    }

    public function messages(): array
    {
        return [
            'student_number.regex' => StudentNumber::FORMAT_MESSAGE,
            'email.unique' => 'This email is already registered.',
        ];
    }

    /** The first two digits are the enrollment year's. */
    private function matchesEnrollmentYear(string $attribute, mixed $value, Closure $fail): void
    {
        $prefix = StudentNumber::yearPrefix($this->input('enrollment_date'));
        if ($prefix !== null && StudentNumber::isValid($value) && substr($value, 0, 2) !== $prefix) {
            $fail(StudentNumber::wrongYearMessage($prefix));
        }
    }
}
