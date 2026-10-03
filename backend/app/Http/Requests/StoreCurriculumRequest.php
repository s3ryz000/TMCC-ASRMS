<?php

namespace App\Http\Requests;

use App\Models\Subject;
use App\Services\CurriculumPrerequisites;
use App\Support\CurriculumRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create a program together with its curriculum (#70):
 *
 *   { program: {code, name, description?},
 *     entries: [{subject_id | new_subject: {code, title, units, description?}, year_level, semester,
 *                prerequisites?: [entry indexes], prerequisite_logic?: AND|OR}] }
 *
 * The program follows SaveProgramRequest, new subjects follow
 * SaveSubjectRequest (registrar code format, no duplicate codes or
 * near-duplicate titles, also among the new subjects themselves) and every
 * entry follows the placement rules of the entries API (#24). Errors are
 * keyed by entry index, e.g. entries.3.subject_id.
 */
class StoreCurriculumRequest extends FormRequest
{
    public const MAX_ENTRIES = 100;

    public function authorize(): bool
    {
        return true;
    }

    /** New subject codes in the registrar format and titles trimmed, as in SaveSubjectRequest. */
    protected function prepareForValidation(): void
    {
        $entries = $this->input('entries');
        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $i => $entry) {
            if (is_array($entry) && is_string($logic = $entry['prerequisite_logic'] ?? null)) {
                $entries[$i]['prerequisite_logic'] = strtoupper(trim($logic));
            }
            if (! is_array($entry) || ! is_array($entry['new_subject'] ?? null)) {
                continue;
            }
            if (is_string($code = $entry['new_subject']['code'] ?? null)) {
                $entries[$i]['new_subject']['code'] = Subject::formatCode($code);
            }
            if (is_string($title = $entry['new_subject']['title'] ?? null)) {
                $entries[$i]['new_subject']['title'] = SaveSubjectRequest::cleanTitle($title);
            }
        }

        $this->merge(['entries' => $entries]);
    }

    public function rules(): array
    {
        $rules = [
            'program'                => ['required', 'array'],
            'entries'                => ['required', 'array', 'min:1', 'max:' . self::MAX_ENTRIES],
            'entries.*'              => ['required', 'array'],
            'entries.*.subject_id'   => ['nullable', 'required_without:entries.*.new_subject', 'prohibits:entries.*.new_subject', 'integer', Rule::exists('subjects', 'id')],
            'entries.*.new_subject'  => ['nullable', 'required_without:entries.*.subject_id', 'array'],
            'entries.*.year_level'   => CurriculumRules::YEAR_LEVEL,
            'entries.*.semester'     => CurriculumRules::semester(),
            // Prerequisites point at other entries of this payload by index (#27).
            'entries.*.prerequisites'      => ['sometimes', 'array', 'max:20'],
            'entries.*.prerequisites.*'    => ['integer', 'min:0', 'distinct'],
            'entries.*.prerequisite_logic' => ['nullable', Rule::in(CurriculumPrerequisites::LOGIC)],
        ];

        foreach (SaveProgramRequest::fieldRules() as $field => $fieldRules) {
            $rules["program.{$field}"] = $fieldRules;
        }

        // Required only for entries that describe a new subject.
        foreach (SaveSubjectRequest::FIELD_RULES as $field => $fieldRules) {
            $rules["entries.*.new_subject.{$field}"] = array_map(
                fn ($rule) => $rule === 'required' ? 'required_with:entries.*.new_subject' : $rule,
                $fieldRules,
            );
        }

        return $rules;
    }

    public function messages(): array
    {
        $choose = 'Choose an existing subject or describe a new one.';

        return [
            'program.code.unique'                  => SaveProgramRequest::CODE_TAKEN,
            'entries.required'                     => 'Add at least one subject to the curriculum.',
            'entries.min'                          => 'Add at least one subject to the curriculum.',
            'entries.*.subject_id.required_without' => $choose,
            'entries.*.new_subject.required_without' => $choose,
            'entries.*.subject_id.prohibits'       => 'Choose either an existing subject or a new one, not both.',
            'entries.*.subject_id.exists'          => 'This subject does not exist.',
            'entries.*.new_subject.*.required_with' => 'The :attribute field is required.',
        ];
    }

    public function attributes(): array
    {
        return [
            'program.code'                   => 'program code',
            'program.name'                   => 'program name',
            'program.description'            => 'description',
            'entries.*.year_level'           => 'year level',
            'entries.*.semester'             => 'semester',
            'entries.*.new_subject.code'     => 'subject code',
            'entries.*.new_subject.title'    => 'descriptive title',
            'entries.*.new_subject.units'    => 'units',
            'entries.*.new_subject.description' => 'description',
        ];
    }

    /** Rules that need the database or the other entries. */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkEntries($validator),
            fn (Validator $validator) => $this->checkPrerequisites($validator),
        ];
    }

    /**
     * Each prerequisite is another entry of this payload, at an earlier term
     * (#27). Links that only point to earlier terms can't form a loop, so the
     * editor's chain check isn't needed for a new curriculum.
     */
    private function checkPrerequisites(Validator $validator): void
    {
        $entries = $this->input('entries');
        if (! is_array($entries)) {
            return;
        }

        $errors = $validator->errors();
        $existing = Subject::whereIn('id', collect($entries)->pluck('subject_id')->filter(fn ($id) => is_int($id) || ctype_digit((string) $id)))
            ->pluck('code', 'id');
        $code = fn ($i) => is_array($entries[$i] ?? null)
            ? ($existing[(int) ($entries[$i]['subject_id'] ?? 0)] ?? $entries[$i]['new_subject']['code'] ?? 'Entry ' . ((int) $i + 1))
            : 'Entry ' . ((int) $i + 1);
        $term = fn ($entry) => is_array($entry)
            && in_array($entry['year_level'] ?? null, [1, 2, 3, 4, '1', '2', '3', '4'], true)
            && ($semester = CurriculumRules::semesterNumber($entry['semester'] ?? null))
                ? CurriculumPrerequisites::termIndex($entry['year_level'], $semester)
                : null;

        foreach ($entries as $i => $entry) {
            if (! is_array($entry) || ! is_array($entry['prerequisites'] ?? null)) {
                continue;
            }
            $entryTerm = $term($entry);

            foreach (array_values($entry['prerequisites']) as $j => $index) {
                $key = "entries.{$i}.prerequisites.{$j}";
                if ($errors->has($key) || ! (is_int($index) || ctype_digit((string) $index))) {
                    continue;
                }
                $index = (int) $index;
                $other = $entries[$index] ?? null;

                if ($index === (int) $i) {
                    $errors->add($key, "{$code($i)} can't be its own prerequisite.");
                } elseif (! is_array($other)) {
                    $errors->add($key, 'This prerequisite is not in this curriculum.');
                } elseif ($entryTerm !== null && ($otherTerm = $term($other)) !== null && $otherTerm >= $entryTerm) {
                    $errors->add($key, CurriculumPrerequisites::notEarlier(
                        $code($index), $other['year_level'], CurriculumRules::semesterNumber($other['semester']),
                        $code($i), $entry['year_level'], CurriculumRules::semesterNumber($entry['semester']),
                    ));
                }
            }
        }
    }

    private function checkEntries(Validator $validator): void
    {
        $entries = $this->input('entries');
        if (! is_array($entries)) {
            return;
        }

        $errors = $validator->errors();
        $programCode = is_string($code = $this->input('program.code')) && $code !== '' ? $code : 'this program';
        $subjects = Subject::whereIn('id', collect($entries)->pluck('subject_id')->filter(fn ($id) => is_int($id) || ctype_digit((string) $id)))
            ->get()
            ->keyBy('id');

        $placed = [];     // subject id => [year level, semester]
        $newCodes = [];   // formatted code => true
        $newTitles = [];  // normalized title => true

        foreach ($entries as $i => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $term = [$entry['year_level'] ?? null, CurriculumRules::semesterNumber($entry['semester'] ?? null)];

            if (isset($entry['subject_id']) && ! $errors->has("entries.{$i}.subject_id")) {
                $subject = $subjects[(int) $entry['subject_id']] ?? null;
                if (! $subject) {
                    continue;
                }
                if ($subject->archived_at !== null) {
                    $errors->add("entries.{$i}.subject_id", CurriculumRules::archivedSubject($subject));
                } elseif (isset($placed[$subject->id])) {
                    [$year, $semester] = $placed[$subject->id];
                    $errors->add("entries.{$i}.subject_id", $year && $semester
                        ? CurriculumRules::alreadyPlaced($subject->code, $programCode, $year, $semester)
                        : "{$subject->code} is already in this curriculum.");
                } else {
                    $placed[$subject->id] = $term;
                }

                continue;
            }

            $new = $entry['new_subject'] ?? null;
            if (! is_array($new)) {
                continue;
            }

            $codeKey = "entries.{$i}.new_subject.code";
            if (is_string($new['code'] ?? null) && ! $errors->has($codeKey)) {
                if (Subject::withCode($new['code'])) {
                    $errors->add($codeKey, SaveSubjectRequest::CODE_TAKEN);
                } elseif (isset($newCodes[$new['code']])) {
                    $errors->add($codeKey, 'Another new subject in this curriculum already uses this code.');
                } else {
                    $newCodes[$new['code']] = true;
                }
            }

            $titleKey = "entries.{$i}.new_subject.title";
            if (is_string($new['title'] ?? null) && ! $errors->has($titleKey)) {
                $normalized = Subject::normalizeTitle($new['title']);
                if ($existing = Subject::withSimilarTitle($new['title'])) {
                    $errors->add($titleKey, SaveSubjectRequest::similarTitleMessage($existing));
                } elseif (isset($newTitles[$normalized])) {
                    $errors->add($titleKey, 'Another new subject in this curriculum already has this title.');
                } else {
                    $newTitles[$normalized] = true;
                }
            }
        }
    }
}
