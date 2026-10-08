<?php

namespace App\Http\Controllers\Student;

use App\Support\AcademicStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Http\Requests\UpdateStudentSisRequest;
use App\Models\SystemLog;
use App\Models\SystemSetting;
use App\Models\PendingStudentUpdate;
use App\Models\Student;
use App\Services\Enrollment\EnrollmentTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

/**
 * Student: profile, COR, subjects, grades, curriculum (own data only).
 */
class StudentProfileController extends Controller
{
    use AuthorizesRole;

    /** Plain names of the SIS fields, for logs and the student's request list. */
    private const FIELD_LABELS = [
        'contact_number'    => 'contact number',
        'address'           => 'address',
        'place_of_birth'    => 'place of birth',
        'sex'               => 'sex',
        'guardian_name'     => 'guardian name',
        'citizenship'       => 'citizenship',
        'elementary_school' => 'elementary school',
        'elementary_year'   => 'elementary graduation year',
        'high_school'       => 'high school',
        'high_school_year'  => 'high school graduation year',
        'previous_school'   => 'previous school',
        'previous_course'   => 'previous course',
    ];

    /** Fields that need a supporting document when changed. */
    private const DOCUMENT_REQUIRED_FIELDS = [
        'address', 'place_of_birth', 'sex', 'guardian_name', 'citizenship',
        'contact_number', 'elementary_school', 'elementary_year',
        'high_school', 'high_school_year', 'previous_school', 'previous_course',
    ];

    /**
     * Get authenticated student's profile (student record + program + user).
     */
    public function profile(Request $request): JsonResponse
    {
       try {
       
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['student'])) {
            return $err;
        }

        $student = $request->user()->student;
        if (! $student) {
            return response()->json(['message' => 'Student record not found.'], 404);
        }

        $student->load('program');
        $academicYear = SystemSetting::getValue('academic_year') ?: date('Y') . '-' . (date('Y') + 1);
        $semester = SystemSetting::getValue('semester') ?: '2nd Semester';

        // The program is the student's own (students.program_id) and the
        // current term and year level come from their latest active
        // enrollment. program_mappings duplicated both and went stale after a
        // program change (#21); the response keeps its old keys and shape.
        $latest = $student->enrollments()
            ->whereNotIn('status', AcademicStatus::NOT_ACTIVE)
            ->orderByDesc('academic_year')
            ->orderByDesc('semester')
            ->orderByDesc('id')
            ->first();
        $programMapping = $student->program ? [
            'program_id'    => $student->program_id,
            'program'       => $student->program,
            'academic_year' => $latest?->academic_year,
            'semester'      => $latest?->semester,
            'year_level'    => $latest?->year_level,
        ] : null;

        $service = app(\App\Services\AcademicStandingService::class);
        $summary = $service->getAcademicSummary($student);

        return response()->json([
            'student' => $student,
            'academic_year' => $academicYear,
            'program' => $student->program,
            'program_mapping' => $programMapping,
            'semester' => $semester,
            'institution_name' => SystemSetting::getValue('institution_name') ?: 'Trece Martires City College',
            'academic_summary' => $summary,
        ]);
       } catch (\Exception $e) {
        return response()->json([
            'message' => 'An error occurred while fetching the profile.',
            'error' => config('app.debug') ? $e->getMessage() : 'Something went wrong.',
        ], 500);
       }
    }

    /**
     * Certificate of Registration: enrolled subjects for the given or current term.
     */
    public function cor(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['student'])) {
            return $err;
        }

        $student = $request->user()->student;
        if (! $student) {
            return response()->json(['message' => 'Student record not found.'], 404);
        }

        $validated = $request->validate([
            'academic_year' => ['nullable', 'string', 'max:20'],
            'semester' => ['nullable', 'string', 'max:20'],
        ]);

        // The term's registered subjects (#83). The semester used to default to
        // the text "2nd Semester" while enrollments store 1 or 2, so nothing
        // matched. Semesters are compared normalised ("2", "2nd Semester" → 2),
        // and without a term in the request the student's latest term is used.
        $active = $student->enrollments()
            ->with('subject')
            ->whereNotIn('status', AcademicStatus::NOT_ACTIVE)
            ->get()
            ->map(function ($e) {
                $e->setAttribute('term_semester', EnrollmentTerm::normaliseSemester($e->semester));

                return $e;
            });

        if (filled($validated['semester'] ?? null)) {
            $semester = EnrollmentTerm::normaliseSemester($validated['semester']);
            if ($semester === null) {
                return response()->json([
                    'message' => 'The semester must be 1st or 2nd.',
                    'errors' => ['semester' => ['The semester must be 1st or 2nd.']],
                ], 422);
            }
            $academicYear = $validated['academic_year'] ?? $active->where('term_semester', $semester)->max('academic_year');
        } elseif (filled($validated['academic_year'] ?? null)) {
            $academicYear = $validated['academic_year'];
            $semester = $active->where('academic_year', $academicYear)->max('term_semester');
        } else {
            $latest = $active->filter(fn ($e) => $e->academic_year && $e->term_semester)
                ->sortBy([['academic_year', 'desc'], ['term_semester', 'desc']])
                ->first();
            $academicYear = $latest?->academic_year;
            $semester = $latest?->term_semester;
        }

        $enrollments = $active
            ->filter(fn ($e) => $e->academic_year === $academicYear && $e->term_semester === $semester)
            ->sortBy(fn ($e) => $e->subject?->code ?? '')
            ->values()
            ->each(fn ($e) => $e->offsetUnset('term_semester'));

        return response()->json([
            'academic_year' => $academicYear,
            'semester' => $semester,
            'student' => $student->load('program'),
            'enrollments' => $enrollments,
        ]);
    }

    /**
     * Enrolled subjects (all or filter by term).
     */
    public function subjects(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['student'])) {
            return $err;
        }

        $student = $request->user()->student;
        if (! $student) {
            return response()->json(['message' => 'Student record not found.'], 404);
        }

        $query = $student->enrollments()->with('subject');
        if ($ay = $request->input('academic_year')) {
            $query->where('enrollments.academic_year', $ay);
        }
        if ($sem = $request->input('semester')) {
            $query->where('enrollments.semester', $sem);
        }
        $enrollments = $query->orderByDesc('enrollments.academic_year')->orderBy('enrollments.semester')->get();

        return response()->json(['enrollments' => $enrollments]);
    }

    /**
     * Grades (all or filter by term).
     */
    public function grades(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['student'])) {
            return $err;
        }

        $student = $request->user()->student;
        if (! $student) {
            return response()->json(['message' => 'Student record not found.'], 404);
        }

        $query = $student->grades()->with('subject');
        if ($ay = $request->input('academic_year')) {
            $query->where('grades.academic_year', $ay);
        }
        if ($sem = $request->input('semester')) {
            $query->where('grades.semester', $sem);
        }
        $grades = $query->orderByDesc('grades.academic_year')->orderBy('grades.semester')->get()->sortBy(fn ($g) => $g->subject?->code ?? '')->values();

        return response()->json(['grades' => $grades]);
    }

    /**
     * Curriculum for the student's program (subjects by year level and semester).
     */
    public function curriculum(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['student'])) {
            return $err;
        }

        $student = $request->user()->student;
        if (! $student) {
            return response()->json(['message' => 'Student record not found.'], 404);
        }

        $program = $student->program;
        if (! $program) {
            return response()->json([
                'program' => null,
                'curriculum' => [],
                'message' => 'No program assigned.',
            ]);
        }

        $curriculum = $program->curriculum()->with('subject')->orderBy('year_level')->orderBy('semester')->get();

        // The prospectus still lists archived subjects, marked (#68).
        $curriculum->each(fn ($row) => $row->subject?->append('archived'));

        return response()->json([
            'program' => $program,
            'curriculum' => $curriculum,
        ]);
    }

    /**
     * Update authenticated student's SIS/SIUF fields (own record only).
     */
    public function updateSis(UpdateStudentSisRequest $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['student'])) {
            return $err;
        }

        $student = $request->user()->student;
        if (! $student) {
            return response()->json(['message' => 'Student record not found.'], 404);
        }

        [$oldValues, $newValues, $changedFields] = $this->changesFrom($student, $request->validated());
        $supportingDocument = $request->file('supporting_document');

        if (empty($changedFields)) {
            return response()->json([
                'message' => 'No changes detected.'
            ]);
        }

        if ($this->needsDocument($changedFields) && !$supportingDocument) {
            return $this->documentRequired();
        }

        PendingStudentUpdate::create([
            'student_id' => $student->student_id,
            'submitted_by' => $request->user()->id,
            'status' => PendingStudentUpdate::STATUS_PENDING,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'changed_fields' => $changedFields,
            ...$this->storeDocument($supportingDocument),
        ]);

        $studentName   = trim($student->first_name . ' ' . $student->last_name);
        $studentNumber = $student->student_number ?? "ID#{$student->student_id}";
        $changedStr    = implode(', ', $this->labels($changedFields));
        $ip            = $request->ip();

        SystemLog::create([
            'action'  => "Student {$studentNumber} ({$studentName}) submitted profile update for approval: {$changedStr} [IP: {$ip}]",
            'user_id' => $request->user()->id,
            'role'    => $request->user()->roles->first()?->name ?? $request->user()->role ?? null,
        ]);

        return response()->json([
            'message' => 'Your changes were submitted and are pending registrar approval.',
            'student' => $student->load('program'),
        ]);
    }

    /**
     * GET /api/student/profile-updates: the student's own update requests,
     * newest first, with the registrar's decision and reason or remarks (#88).
     */
    public function profileUpdates(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['student'])) {
            return $err;
        }

        $student = $request->user()->student;
        if (! $student) {
            return response()->json(['message' => 'Student record not found.'], 404);
        }

        $updates = PendingStudentUpdate::where('student_id', $student->student_id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PendingStudentUpdate $u) => [
                'id' => $u->id,
                'status' => $u->status,
                'fields' => collect($u->changed_fields ?? [])->map(fn ($field) => [
                    'field' => $field,
                    'label' => self::FIELD_LABELS[$field] ?? str_replace('_', ' ', $field),
                    'old' => $u->old_values[$field] ?? null,
                    'new' => $u->new_values[$field] ?? null,
                ])->values(),
                'submitted_at' => $u->created_at?->toDateTimeString(),
                'decided_at' => $u->reviewed_at?->toDateTimeString(),
                'reason' => $u->rejection_reason,
                'history' => $u->review_history ?? [],
                'has_supporting_document' => $u->has_supporting_document,
                'supporting_document_name' => $u->supporting_document_original_name,
            ]);

        return response()->json(['data' => $updates]);
    }

    /**
     * POST /api/student/profile-updates/{id}/resubmit: the student corrects a
     * request returned for revision and sends it back to the registrar. The
     * same request goes back to pending; the previous remarks stay in its
     * review history. A new document replaces the old one; without one, the
     * document already attached is kept.
     */
    public function resubmitProfileUpdate(UpdateStudentSisRequest $request, int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['student'])) {
            return $err;
        }

        $student = $request->user()->student;
        if (! $student) {
            return response()->json(['message' => 'Student record not found.'], 404);
        }

        // Another student's request is simply not found.
        $update = PendingStudentUpdate::where('student_id', $student->student_id)->find($id);
        if (! $update) {
            return response()->json(['message' => 'Update request not found.'], 404);
        }
        if ($update->status !== PendingStudentUpdate::STATUS_REVISION_REQUIRED) {
            return response()->json(['message' => 'Only a request returned for revision can be resubmitted.'], 422);
        }

        [$oldValues, $newValues, $changedFields] = $this->changesFrom($student, $request->validated());
        $supportingDocument = $request->file('supporting_document');

        if (empty($changedFields)) {
            return response()->json([
                'message' => 'Your corrected values are the same as your current record. Change at least one field.',
            ], 422);
        }

        if ($this->needsDocument($changedFields) && ! $supportingDocument && ! $update->has_supporting_document) {
            return $this->documentRequired();
        }

        $previousDocument = $supportingDocument ? $update->supporting_document_path : null;

        $update->fill([
            'status' => PendingStudentUpdate::STATUS_PENDING,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'changed_fields' => $changedFields,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
            ...($supportingDocument ? $this->storeDocument($supportingDocument) : []),
        ]);
        $update->recordHistory('resubmitted');
        $update->save();

        if ($previousDocument) {
            Storage::disk('local')->delete($previousDocument);
        }

        $studentName   = trim($student->first_name . ' ' . $student->last_name);
        $studentNumber = $student->student_number ?? "ID#{$student->student_id}";
        $changedStr    = implode(', ', $this->labels($changedFields));

        SystemLog::create([
            'action'  => "Student {$studentNumber} ({$studentName}) resubmitted a returned profile update for approval: {$changedStr} [IP: {$request->ip()}]",
            'user_id' => $request->user()->id,
            'role'    => $request->user()->roles->first()?->name ?? $request->user()->role ?? null,
        ]);

        return response()->json([
            'message' => 'Your corrected request was resubmitted and is pending registrar approval.',
        ]);
    }

    /**
     * The submitted SIS values that differ from the student's record, as
     * [old values, new values, changed field names].
     */
    private function changesFrom(Student $student, array $validated): array
    {
        unset($validated['supporting_document']);

        $oldValues = [];
        $newValues = [];
        $changedFields = [];

        foreach ($validated as $field => $newVal) {
            $oldVal = $student->getAttribute($field);

            // Format dates if necessary
            if ($oldVal instanceof \Carbon\Carbon || $oldVal instanceof \Illuminate\Support\Carbon) {
                $oldVal = $oldVal->format('Y-m-d');
            }

            if ((string) $oldVal !== (string) ($newVal ?? '')) {
                $oldValues[$field] = $oldVal;
                $newValues[$field] = $newVal;
                $changedFields[] = $field;
            }
        }

        return [$oldValues, $newValues, $changedFields];
    }

    private function labels(array $fields): array
    {
        return array_map(fn ($field) => self::FIELD_LABELS[$field] ?? str_replace('_', ' ', $field), $fields);
    }

    private function needsDocument(array $changedFields): bool
    {
        return ! empty(array_intersect($changedFields, self::DOCUMENT_REQUIRED_FIELDS));
    }

    private function documentRequired(): JsonResponse
    {
        return response()->json([
            'message' => 'A supporting document is required for the fields you modified.',
            'errors' => ['supporting_document' => ['Proof document is required.']]
        ], 422);
    }

    /** Stores the uploaded proof and returns its supporting_document_* columns. */
    private function storeDocument(?UploadedFile $file): array
    {
        return [
            'supporting_document_path' => $file?->store('pending-profile-updates', 'local'),
            'supporting_document_original_name' => $file?->getClientOriginalName(),
            'supporting_document_mime' => $file?->getMimeType(),
            'supporting_document_size' => $file?->getSize(),
        ];
    }

    /**
     * GET /api/student/academic-summary
     * Returns the authenticated student's academic summary.
     */
    public function academicSummary(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['student'])) {
            return $err;
        }

        $student = $request->user()->student;
        if (! $student) {
            return response()->json(['message' => 'Student record not found.'], 404);
        }

        $student->load('program');
        
        $standingService = app(\App\Services\AcademicStandingService::class);
        $progressionService = app(\App\Services\AcademicProgressionService::class);
        
        $summary = $standingService->getAcademicSummary($student);
        $roadmapData = $progressionService->getCurriculumRoadmap($student);
        
        $residencyService = app(\App\Services\AcademicResidencyValidationService::class);
        $residency = $residencyService->computeResidency($student);

        // Compute Notifications
        $notifications = [];
        
        if ($summary['latin_honors']['eligible']) {
            $notifications[] = [
                'type' => 'success',
                'message' => 'Congratulations! You are currently eligible for Latin Honors: ' . $summary['latin_honors']['honor']
            ];
        }
        
        if ($roadmapData['failed_subjects_count'] > 0) {
            $notifications[] = [
                'type' => 'warning',
                'message' => 'You have ' . $roadmapData['failed_subjects_count'] . ' failed subject(s) that require a retake.'
            ];
        }

        return response()->json([
            'student' => [
                'student_number' => $student->student_number,
                'name' => trim($student->first_name . ' ' . $student->last_name),
                'program' => $student->program?->name,
                'program_code' => $student->program?->code,
                'enrollment_date' => $student->enrollment_date?->toDateString(),
            ],
            'summary' => $summary,
            'curriculum' => $roadmapData,
            'notifications' => $notifications,
            'residency' => $residency
        ]);
    }
}
