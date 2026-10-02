<?php

namespace App\Http\Requests;

use App\Models\Subject;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Create or update a subject. Every course has one subject row with one
 * unique code, shared by all programs (#16), so the code must be unique on
 * its own and a new title must not be a spelling variant of an existing
 * subject's.
 */
class SaveSubjectRequest extends FormRequest
{
    private Subject|false|null $current = false;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Codes are stored in the registrar's format: no spaces or dashes,
     * uppercase ("thc 11", "THC-11" -> "THC11"; see Subject::formatCode).
     * Re-submitting a subject's own code in any spelling keeps the stored one,
     * so editing a record never silently renames it.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($code = $this->input('code'))) {
            $code = Subject::formatCode($code);
            $current = $this->currentSubject();
            $merge['code'] = $current && Subject::formatCode($current->code) === $code
                ? $current->code
                : $code;
        }

        if (is_string($title = $this->input('title'))) {
            $merge['title'] = preg_replace('/\s+/u', ' ', trim($title));
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        return [
            'code'        => ['required', 'string', 'max:20', $this->uniqueCode(...)],
            'title'       => ['required', 'string', 'max:150'],
            'units'       => ['required', 'integer', 'min:0', 'max:12'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** Refuse a title that is a spelling variant of another subject's. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('title') || ! is_string($this->input('title'))) {
                    return;
                }

                $normalized = Subject::normalizeTitle($this->input('title'));
                $current = $this->currentSubject();

                // Keeping (or re-spacing) its own title is always allowed.
                if ($current && Subject::normalizeTitle($current->title) === $normalized) {
                    return;
                }

                $existing = Subject::query()
                    ->when($current, fn ($q) => $q->whereKeyNot($current->id))
                    ->get(['id', 'code', 'title'])
                    ->first(fn (Subject $subject) => Subject::normalizeTitle($subject->title) === $normalized);

                if ($existing) {
                    $validator->errors()->add(
                        'title',
                        "This looks like {$existing->code} {$existing->title}, which already exists. Reuse it instead."
                    );
                }
            },
        ];
    }

    /**
     * Codes are compared in the registrar's format, ignoring case, spaces and
     * dashes, so "GEC 4" collides with "GEC4" and SQLite and MySQL agree.
     */
    private function uniqueCode(string $attribute, mixed $value, Closure $fail): void
    {
        $taken = Subject::query()
            ->whereRaw("UPPER(REPLACE(REPLACE(code, ' ', ''), '-', '')) = ?", [Subject::formatCode((string) $value)])
            ->when($this->currentSubject(), fn ($q, $current) => $q->whereKeyNot($current->id))
            ->exists();

        if ($taken) {
            $fail('A subject with this code already exists.');
        }
    }

    /** The subject being updated, or null when creating. */
    private function currentSubject(): ?Subject
    {
        if ($this->current === false) {
            $id = $this->route('id');
            $this->current = $id ? Subject::find($id) : null;
        }

        return $this->current;
    }
}
