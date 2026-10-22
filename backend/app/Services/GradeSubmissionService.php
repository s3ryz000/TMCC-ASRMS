<?php

namespace App\Services;

use App\Models\Grade;
use App\Models\GradeSubmission;
use App\Models\Student;
use App\Models\SystemLog;
use App\Models\User;
use App\Services\Enrollment\EnrollmentTerm;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Grade double approval (#107). Staff encode grades into a pending
 * submission; a registrar with "approve-grades" (not the encoder) approves it,
 * and only then are the grades written, through GradeChangeService, with the
 * usual audit trail. A grades row therefore only ever holds approved values.
 */
class GradeSubmissionService
{
    public const CONFLICT_MESSAGE = 'Grades for this term are waiting for approval.';

    public function __construct(private GradeChangeService $changes)
    {
    }

    /**
     * Check every row without saving; when all pass, put them into one
     * pending submission per term. The encoder's own pending submission for a
     * term is updated in place (rows for the same grade are replaced); another
     * user's pending submission for the term refuses the whole request.
     *
     * @param array<int, array{grade_id: int, grade_value?: mixed, status?: ?string, remarks?: ?string, supporting_document_reference?: ?string}> $rows
     * @return array{errors: string[], conflict: ?string, submissions: GradeSubmission[]}
     */
    public function submit(Student $student, array $rows, User $user): array
    {
        $grades = Grade::with('subject')
            ->where('student_id', $student->student_id)
            ->whereIn('id', array_column($rows, 'grade_id'))
            ->get()
            ->keyBy('id');

        $errors = [];
        foreach ($rows as $row) {
            $grade = $grades->get($row['grade_id']);
            if (! $grade) {
                $errors[] = "Grade #{$row['grade_id']} not found for this student.";
            } elseif ($problem = $this->changes->problem($grade, $row)) {
                $errors[] = $problem;
            }
        }
        if ($errors) {
            return ['errors' => $errors, 'conflict' => null, 'submissions' => []];
        }

        // One submission per term.
        $byTerm = [];
        foreach ($rows as $row) {
            $grade = $grades->get($row['grade_id']);
            $byTerm[self::termKey($grade->academic_year, $grade->semester)][] = $row;
        }

        $pending = [];
        foreach (array_keys($byTerm) as $key) {
            $first = $grades->get($byTerm[$key][0]['grade_id']);
            $open = $this->pendingFor($student, $first->academic_year, $first->semester);
            if ($open && (int) $open->submitted_by !== (int) $user->id) {
                return ['errors' => [], 'conflict' => self::CONFLICT_MESSAGE, 'submissions' => []];
            }
            $pending[$key] = $open;
        }

        $submissions = DB::transaction(function () use ($byTerm, $pending, $grades, $student, $user) {
            $saved = [];
            foreach ($byTerm as $key => $termRows) {
                $first = $grades->get($termRows[0]['grade_id']);
                $submission = $pending[$key] ?? GradeSubmission::create([
                    'student_id'    => $student->student_id,
                    'academic_year' => $first->academic_year,
                    'semester'      => $first->semester,
                    'status'        => GradeSubmission::STATUS_PENDING,
                    'step'          => GradeSubmission::STEP_REGISTRAR,
                    'submitted_by'  => $user->id,
                    'submitted_at'  => now(),
                ]);
                if ($pending[$key] ?? null) {
                    $submission->update(['submitted_at' => now()]);
                }

                foreach ($termRows as $row) {
                    $grade = $grades->get($row['grade_id']);
                    $submission->items()->updateOrCreate(['grade_id' => $grade->id], [
                        'grade_value'                   => isset($row['grade_value']) && $row['grade_value'] !== '' ? round((float) $row['grade_value'], 2) : null,
                        'status'                        => $row['status'] ?? null,
                        'remarks'                       => ($row['remarks'] ?? null) ?: null,
                        'supporting_document_reference' => $row['supporting_document_reference'] ?? null,
                        'old_value'                     => self::snapshot($grade),
                    ]);
                }

                $this->log($user, 'Submitted ' . count($termRows) . ' grade(s) for approval for student ' . self::describe($student, $submission));
                $saved[] = $submission->fresh('items');
            }

            return $saved;
        });

        return ['errors' => [], 'conflict' => null, 'submissions' => $submissions];
    }

    /** The pending submission for a student's term, if any. Semesters compare normalised ("1" = "1st"). */
    public function pendingFor(Student $student, string $academicYear, string $semester): ?GradeSubmission
    {
        return GradeSubmission::where('student_id', $student->student_id)
            ->where('academic_year', $academicYear)
            ->where('status', GradeSubmission::STATUS_PENDING)
            ->get()
            ->first(fn (GradeSubmission $s) => self::termKey($s->academic_year, $s->semester) === self::termKey($academicYear, $semester));
    }

    /** Whether a grade is in a pending submission. */
    public function gradeIsPending(Grade $grade): bool
    {
        return GradeSubmission::where('status', GradeSubmission::STATUS_PENDING)
            ->whereHas('items', fn ($q) => $q->where('grade_id', $grade->id))
            ->exists();
    }

    /**
     * Apply every item in one transaction, then recompute the GWA once. If any
     * item can't be applied (the grade changed since it was submitted, or a
     * rule now refuses it) nothing is saved and the reason is returned.
     */
    public function approve(GradeSubmission $submission, User $approver, ?string $role): ?string
    {
        try {
            DB::transaction(function () use ($submission, $approver, $role) {
                $submission->load('items', 'student', 'submitter');

                foreach ($submission->items as $item) {
                    $grade = Grade::with('subject')
                        ->where('student_id', $submission->student_id)
                        ->find($item->grade_id);
                    $code = $grade?->subject?->code ?? "Grade #{$item->grade_id}";

                    if (! $grade) {
                        throw new RuntimeException("{$code} no longer exists. Return this submission so it can be encoded again.");
                    }
                    if (self::snapshot($grade) != $item->old_value) {
                        throw new RuntimeException("{$code} changed after these grades were submitted. Return this submission so it can be encoded again.");
                    }

                    $error = $this->changes->apply($grade, [
                        'grade_value'                   => $item->grade_value,
                        'status'                        => $item->status,
                        'remarks'                       => $item->remarks,
                        'supporting_document_reference' => $item->supporting_document_reference,
                    ], $approver, $role);
                    if ($error !== null) {
                        throw new RuntimeException($error);
                    }
                }

                $submission->update([
                    'status'     => GradeSubmission::STATUS_APPROVED,
                    'decided_by' => $approver->id,
                    'decided_at' => now(),
                ]);

                app(AcademicStandingService::class)->recomputeAndCacheOverallGwa($submission->student->fresh());

                $this->log($approver, 'Approved ' . $submission->items->count() . ' grade(s) for student '
                    . self::describe($submission->student, $submission) . ', encoded by ' . ($submission->submitter?->name ?? 'unknown'));
            });
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function returnToEncoder(GradeSubmission $submission, User $approver, string $remarks): void
    {
        $submission->update([
            'status'     => GradeSubmission::STATUS_RETURNED,
            'decided_by' => $approver->id,
            'decided_at' => now(),
            'remarks'    => $remarks,
        ]);
        $submission->load('student', 'items');

        $this->log($approver, 'Returned ' . $submission->items->count() . ' grade(s) to the encoder for student ' . self::describe($submission->student, $submission));
    }

    public function cancel(GradeSubmission $submission, User $encoder): void
    {
        $submission->update([
            'status'     => GradeSubmission::STATUS_CANCELLED,
            'decided_by' => $encoder->id,
            'decided_at' => now(),
        ]);
        $submission->load('student', 'items');

        $this->log($encoder, 'Cancelled a grade submission of ' . $submission->items->count() . ' grade(s) for student ' . self::describe($submission->student, $submission));
    }

    /**
     * Adds each term's approval state to the grade-entry payload (staff only):
     * pending_submission (the proposed values) and last_returned (the
     * approver's remarks) when the term's latest submission was returned.
     */
    public function withApprovalState(array $progress, Student $student): array
    {
        $submissions = GradeSubmission::with(['items', 'submitter:id,name', 'decider:id,name'])
            ->where('student_id', $student->student_id)
            ->where('status', '!=', GradeSubmission::STATUS_CANCELLED)
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (GradeSubmission $s) => self::termKey($s->academic_year, $s->semester));

        foreach ($progress['grouped_enrollments'] ?? [] as $i => $group) {
            /** @var Collection<int, GradeSubmission> $term */
            $term = $submissions->get(self::termKey((string) $group['academic_year'], (string) $group['semester']), collect());
            $pending = $term->firstWhere('status', GradeSubmission::STATUS_PENDING);
            $latest = $term->first();

            $progress['grouped_enrollments'][$i]['pending_submission'] = $pending ? [
                'id'           => $pending->id,
                'status'       => $pending->status,
                'submitted_by' => ['id' => $pending->submitted_by, 'name' => $pending->submitter?->name],
                'submitted_at' => $pending->submitted_at?->toIso8601String(),
                'items'        => $pending->items->map(fn ($item) => [
                    'grade_id'    => $item->grade_id,
                    'grade_value' => $item->grade_value,
                    'status'      => $item->status,
                    'remarks'     => $item->remarks,
                ])->values()->all(),
            ] : null;

            $progress['grouped_enrollments'][$i]['last_returned'] = ($latest && $latest->status === GradeSubmission::STATUS_RETURNED) ? [
                'id'         => $latest->id,
                'remarks'    => $latest->remarks,
                'decided_by' => $latest->decider?->name,
                'decided_at' => $latest->decided_at?->toIso8601String(),
            ] : null;
        }

        return $progress;
    }

    /** The values the approval compares against: what the grade held when it was submitted. */
    public static function snapshot(Grade $grade): array
    {
        return [
            'grade_value' => $grade->grade_value === null ? null : number_format((float) $grade->grade_value, 2, '.', ''),
            'status'      => $grade->status,
            'remarks'     => $grade->remarks,
        ];
    }

    public static function termKey(string $academicYear, string $semester): string
    {
        return $academicYear . '|' . (EnrollmentTerm::normaliseSemester($semester) ?? $semester);
    }

    /** "260001 (Juan Dela Cruz), A.Y. 2026-2027 sem 1", as SystemLog already records students. */
    public static function describe(?Student $student, GradeSubmission $submission): string
    {
        $name = trim(($student?->first_name ?? '') . ' ' . ($student?->last_name ?? ''));
        $ident = $student?->student_number ? "{$student->student_number} ({$name})" : $name;
        $semester = EnrollmentTerm::normaliseSemester($submission->semester) ?? $submission->semester;

        return "{$ident}, A.Y. {$submission->academic_year} sem {$semester}";
    }

    private function log(User $user, string $action): void
    {
        SystemLog::create([
            'action'  => $action,
            'user_id' => $user->id,
            'role'    => $user->roles->first()?->name ?? $user->role ?? null,
        ]);
    }
}
