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
     * Codes are stored trimmed, single-spaced and uppercase. Re-submitting a
     * subject's own code in different case keeps the stored spelling, so
     * editing "PATHFit 1" does not silently rename it.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($code = $this->input('code'))) {
            $code = preg_replace('/\s+/u', ' ', trim($code));
            $current = $this->currentSubject();
            $merge['code'] = $current && mb_strtoupper($current->code) === mb_strtoupper($code)
                ? $current->code
                : mb_strtoupper($code);
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

    /** Codes are compared case-insensitively, so SQLite and MySQL agree. */
    private function uniqueCode(string $attribute, mixed $value, Closure $fail): void
    {
        $taken = Subject::query()
            ->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $value)])
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
