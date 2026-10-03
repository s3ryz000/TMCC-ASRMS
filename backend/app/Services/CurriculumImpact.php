<?php

namespace App\Services;

use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What a curriculum entry already touches (#28): students of its program
 * with any record in its subject, and entries that require its subject.
 *
 * Curriculum changes never rewrite student history. Enrollments and grades
 * keep the term they were recorded against and are never re-validated, so
 * an entry with records is not moved or removed; a revised curriculum for a
 * new batch is a new program (clone, #31). Placing new subjects is always
 * allowed.
 */
class CurriculumImpact
{
    /** Students listed by name in an impact report. */
    public const SHOWN_STUDENTS = 20;

    /**
     * Students of the entry's program with an enrollment (including
     * cancelled and soft-deleted) or a grade in the entry's subject.
     *
     * @return Builder<Student>
     */
    public function studentsWithRecords(Curriculum $entry): Builder
    {
        return Student::query()
            ->where('program_id', $entry->program_id)
            ->where(fn (Builder $q) => $q
                ->whereIn('student_id', Enrollment::withTrashed()->where('subject_id', $entry->subject_id)->select('student_id'))
                ->orWhereIn('student_id', Grade::where('subject_id', $entry->subject_id)->select('student_id')));
    }

    /**
     * Entries of the same program that list this entry's subject as a
     * prerequisite, in term order.
     *
     * @return Collection<int, Curriculum>
     */
    public function entriesRequiring(Curriculum $entry): Collection
    {
        return Curriculum::with('subject')
            ->where('program_id', $entry->program_id)
            ->whereKeyNot($entry->id)
            ->whereHas('prerequisites', fn ($q) => $q->whereKey($entry->subject_id))
            ->get()
            ->sortBy(fn (Curriculum $c) => [(int) $c->year_level, (int) $c->semester, $c->subject->code])
            ->values();
    }

    /**
     * The refusal for moving or removing an entry with student records, or
     * null when there are none.
     */
    public function historyBlock(Curriculum $entry): ?string
    {
        $count = $this->studentsWithRecords($entry)->count();
        if ($count === 0) {
            return null;
        }

        $entry->loadMissing(['subject', 'program']);
        $who = $count === 1 ? '1 student already has' : "{$count} students already have";

        return "{$who} records for {$entry->subject->code} in {$entry->program->code}; it can't be moved or removed.";
    }

    /** The full impact report for GET /staff/curriculum/{entryId}/impact. */
    public function report(Curriculum $entry): array
    {
        $entry->loadMissing(['subject', 'program']);
        $programStudents = Student::where('program_id', $entry->program_id)->select('student_id');

        $enrollments = Enrollment::withTrashed()
            ->where('subject_id', $entry->subject_id)
            ->whereIn('student_id', $programStudents)
            ->get(['status', 'deleted_at']);
        $grades = Grade::where('subject_id', $entry->subject_id)
            ->whereIn('student_id', $programStudents)
            ->pluck('status');

        $students = $this->studentsWithRecords($entry);
        $studentCount = (clone $students)->count();
        $requiredBy = $this->entriesRequiring($entry);
        $historyBlock = $this->historyBlock($entry);

        return [
            'entry' => self::summary($entry) + ['program' => $entry->program->code],
            'students' => [
                'count' => $studentCount,
                'shown' => $students
                    ->orderBy('last_name')->orderBy('first_name')->orderBy('student_number')
                    ->limit(self::SHOWN_STUDENTS)
                    ->get(['student_id', 'student_number', 'first_name', 'last_name'])
                    ->map(fn (Student $s) => [
                        'student_id'     => $s->student_id,
                        'student_number' => $s->student_number,
                        'name'           => trim("{$s->last_name}, {$s->first_name}", ', '),
                    ])
                    ->values(),
            ],
            'enrollments' => [
                'total'        => $enrollments->count(),
                'by_status'    => $this->countByStatus($enrollments->pluck('status')),
                'soft_deleted' => $enrollments->whereNotNull('deleted_at')->count(),
            ],
            'grades' => [
                'total'     => $grades->count(),
                'by_status' => $this->countByStatus($grades),
            ],
            'required_by' => $requiredBy->map(fn (Curriculum $c) => self::summary($c))->values(),
            'can_move'    => $historyBlock === null,
            'can_remove'  => $historyBlock === null && $requiredBy->isEmpty(),
        ];
    }

    /** An entry as impact reports and refusals list it. */
    public static function summary(Curriculum $entry): array
    {
        return [
            'entry_id'   => $entry->id,
            'subject_id' => $entry->subject_id,
            'code'       => $entry->subject->code,
            'title'      => $entry->subject->title,
            'year_level' => (int) $entry->year_level,
            'semester'   => (int) $entry->semester,
        ];
    }

    /** status => count, sorted by status ({} when empty); a missing status counts as "None". */
    private function countByStatus(Collection $statuses): object
    {
        return (object) $statuses
            ->map(fn ($status) => $status ?? 'None')
            ->countBy()
            ->sortKeys()
            ->all();
    }
}
