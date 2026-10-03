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
    /** Field rules, also used for new subjects in the curriculum builder (#70); uniqueness is checked separately. */
    public const FIELD_RULES = [
        'code'        => ['required', 'string', 'max:20'],
        'title'       => ['required', 'string', 'max:150'],
        'units'       => ['required', 'integer', 'min:0', 'max:12'],
        'description' => ['nullable', 'string', 'max:255'],
    ];

    public const CODE_TAKEN = 'A subject with this code already exists.';

    private Subject|false|null $current = false;

    public static function similarTitleMessage(Subject $existing): string
    {
        return "This looks like {$existing->code} {$existing->title}, which already exists. Reuse it instead.";
    }

    /** A title as it is stored: trimmed, inner whitespace collapsed. */
    public static function cleanTitle(string $title): string
    {
        return preg_replace('/\s+/u', ' ', trim($title));
    }

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
            $merge['title'] = self::cleanTitle($title);
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        return array_merge(self::FIELD_RULES, [
            'code' => [...self::FIELD_RULES['code'], $this->uniqueCode(...)],
        ]);
    }

    /** Refuse a title that is a spelling variant of another subject's. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('title') || ! is_string($this->input('title'))) {
                    return;
                }

                $title = $this->input('title');
                $current = $this->currentSubject();

                // Keeping (or re-spacing) its own title is always allowed.
                if ($current && Subject::normalizeTitle($current->title) === Subject::normalizeTitle($title)) {
                    return;
                }

                if ($existing = Subject::withSimilarTitle($title, $current?->id)) {
                    $validator->errors()->add('title', self::similarTitleMessage($existing));
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
        if (Subject::withCode((string) $value, $this->currentSubject()?->id)) {
            $fail(self::CODE_TAKEN);
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
