<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Http\Requests\StoreStudentRequest;
use App\Http\Requests\UpdateStudentRequest;
use App\Models\ArchiveRecord;
use App\Models\Curriculum;
use App\Services\OfficialTranscriptExportService;
use App\Services\AcademicProgressionService;
use App\Services\Enrollment\EnrollmentPolicy;
use App\Services\Enrollment\EnrollmentService;
use App\Services\Enrollment\EnrollmentTerm;
use App\Models\Enrollment;
use App\Models\EnrollmentAuditLog;
use App\Models\Grade;
use App\Models\Program;
use App\Models\ProgramChangeLog;
use App\Models\ProgramMapping;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SystemLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentController extends Controller
{
    use AuthorizesRole;

    /** Statuses the registrar may set on a grade. */
    private const GRADE_STATUSES = ['Enrolled', 'Passed', 'Failed', 'INC', 'Withdrawn', 'FDA', 'Credited', 'DRP', 'CON'];

    /** What an enrollment's status may be set to (#76): the grade statuses plus Cancelled. */
    private const ENROLLMENT_STATUSES = [...self::GRADE_STATUSES, 'Cancelled'];

    /** Older lowercase values some clients still send, mapped to the stored status. */
    private const ENROLLMENT_STATUS_ALIASES = ['enrolled' => 'Enrolled', 'dropped' => 'DRP'];

    /**
     * List students with search, filter by course/status, pagination (staff + admin).
     */
    public function index(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff', 'admin'])) {
            return $err;
        }

        $query = Student::query()->with(['user:id,name,username,email', 'program:id,code,name', 'archiveRecords']);
        if ($search = $request->input('search')) {
            $search = preg_replace('/\s+/', ' ', trim($search));
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('student_number', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
                });
        }
        
        if ($program = $request->input('program')) {
            $program = trim((string) $program);
            if ($program !== '') {
                $query->whereHas('program', function ($q) use ($program) {
                    $q->where('code', $program)->orWhere('name', $program);
                });
            }
        }

        $sortKey = $request->input('sort', 'last_name');
        $sortDir = $request->input('dir', 'asc') === 'desc' ? 'desc' : 'asc';

        $allowedColumns = ['student_id', 'student_number', 'first_name', 'last_name', 'email'];
        if ($sortKey === 'name') {
            $query->orderBy('last_name', $sortDir)->orderBy('first_name', $sortDir);
        } elseif ($sortKey === 'course') {
            $query->leftJoin('programs', 'students.program_id', '=', 'programs.id')
                ->select('students.*')
                ->orderBy('programs.code', $sortDir);
        } elseif (in_array($sortKey, $allowedColumns, true)) {
            $query->orderBy($sortKey, $sortDir);
        } elseif ($sortKey === 'status') {
            $query->orderBy('students.student_id', $sortDir);
        } else {
            $query->orderBy('last_name', 'asc');
        }

        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);
        $students = $query->paginate($perPage);
        // Log::info(ArchiveRecord::where('student_id', $students?->first()?->student_id)->get());

        return response()->json($students);
    }

    /**
     * Store a newly created student and their login account (registrar staff only).
     *
     * Admins are deliberately excluded from every write in this controller:
     * their role is system governance, not record-keeping. Reads stay open to
     * them so they can still oversee and report on what the registrar entered.
     */
    public function store(StoreStudentRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $role = $user->roles->first()?->name ?? $user->role ?? null;
        if (! in_array($role, ['staff'], true)) {
            return response()->json(['message' => 'Forbidden. Registrar staff only.'], 403);
        }

        $validated = $request->validated();


        $subjectIds = $validated['subject_ids'] ?? [];
        unset($validated['subject_ids']);

        $studentNumber = $validated['student_number'];
        $name = trim($validated['first_name'] . ' ' . $validated['last_name']);
        $email = $validated['email'];
        $exactPassword = User::generatePassword();
        $student = DB::transaction(function () use ($validated, $studentNumber, $name, $email, $exactPassword, $subjectIds, $user) {
            $account = User::create([
                'name' => $name,
                'email' => $email,
                'username' => $studentNumber,
                'role' => 'student',
                'password' => Hash::make($exactPassword),
            ]);
            $account->assignRole('student');

            $studentData = $validated;
            $studentData['user_id'] = $account->id;

            $student = Student::create($studentData);

            if (!empty($subjectIds)) {
                // Derived from the enrollment date exactly as the guided
                // next-term flow derives every later term, so a student's 1st
                // and 2nd semester can never land in different academic years.
                $academicYear = app(AcademicProgressionService::class)
                    ->computeAcademicYearForTerm($student, 1, 1);

                // A newly created student starts at Year 1, Semester 1 of their
                // program. Subjects outside that term are a data-entry error, so
                // they fail the whole creation rather than being recorded.
                $term = new EnrollmentTerm(
                    yearLevel: 1,
                    semester: 1,
                    academicYear: $academicYear,
                );

                $result = app(EnrollmentService::class)->enroll(
                    $student,
                    $term,
                    $subjectIds,
                    EnrollmentPolicy::newStudent(),
                    $user,
                );

                // Thrown inside the transaction so the student, account and
                // archive rows all roll back together.
                if ($result->failed()) {
                    throw ValidationException::withMessages([
                        'subject_ids' => $result->errors(),
                    ]);
                }
            }

            ArchiveRecord::create([
                'student_id'      => $student->student_id,
                'record_type'     => $validated['record_type'],
                'cabinet_no'      => $validated['cabinet_no'],
                'shelf_no'        => $validated['shelf_no'],
                'folder_code'     => $validated['folder_code'],
                'document_status' => $validated['document_status'],
            ]);

            return $student;
        });

        SystemLog::create([
            'action' => 'Student created',
            'user_id' => $user->id,
            'role' => $role,
        ]);
        return response()->json([
            'message' => 'Student and account created successfully.',
            'student' => $student,
            'account' => [
                'username' => $studentNumber,
                'password' => $exactPassword,
            ],
        ], 201);
        } catch (ValidationException $e) {
            // Curriculum violations are a 422 with usable messages, not a 500.
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to create student and account: ' . $e->getMessage());
           return response()->json(['message' => 'Failed to create student and account.'], 500);
        }
    }

    /**
     * Display the specified student (staff/admin only).
     */
    public function show(int $id): JsonResponse
    {
        $user = request()->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $role = $user->roles->first()?->name ?? $user->role ?? null;
        if (! in_array($role, ['staff', 'admin'], true)) {
            return response()->json(['message' => 'Forbidden. Staff or Admin only.'], 403);
        }

        $student = Student::with([
            'program',
            'enrollments' => function ($q) {
                // Only return non-soft-deleted enrollments to the frontend
                $q->whereNull('deleted_at')->with('subject');
            },
            'grades.subject',
            'archiveRecords',
        ])->find($id);
        if (! $student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }
        return response()->json(['student' => $student]);
    }

    /**
     * Download the official transcript PDF for a student (staff/admin only).
     */
    public function downloadTranscript(int $id): StreamedResponse|JsonResponse
    {
        $user = request()->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $role = $user->roles->first()?->name ?? $user->role ?? null;
        if (! in_array($role, ['staff', 'admin'], true)) {
            return response()->json(['message' => 'Forbidden. Staff or Admin only.'], 403);
        }

        $student = Student::with(['program', 'grades.subject'])->find($id);
        if (! $student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        return app(OfficialTranscriptExportService::class)->streamForStudent($student);
    }

    /**
     * Update the specified student (registrar staff only).
     */
    public function update(UpdateStudentRequest $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $role = $user->roles->first()?->name ?? $user->role ?? null;
        if (! in_array($role, ['staff'], true)) {
            return response()->json(['message' => 'Forbidden. Registrar staff only.'], 403);
        }

        $student = Student::find($id);
        if (! $student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $validated = $request->validated();


        $studentNumber = $validated['student_number'];
        $name = trim($validated['first_name'] . ' ' . $validated['last_name']);
        $email = $validated['email'];

        $trackFields = [
            'student_number', 'first_name', 'last_name', 'middle_name',
            'date_of_birth', 'sex', 'email', 'contact_number', 'address',
            'enrollment_date', 'graduation_date',
        ];
        $oldValues = [];
        foreach ($trackFields as $field) {
            $oldValues[$field] = $student->getAttribute($field);
        }

        DB::transaction(function () use ($student, $validated, $studentNumber, $name, $email) {
            $student->update($validated);

            if ($student->user) {
                $student->user->update([
                    'name' => $name,
                    'email' => $email,
                    'username' => $studentNumber,
                ]);
            }
        });

        $student->refresh();

        $changed = [];
        foreach ($trackFields as $field) {
            $newVal = $validated[$field] ?? null;
            $oldVal = $oldValues[$field] ?? '';
            
            // Format Carbon date objects to YYYY-MM-DD so they match the frontend payload
            if ($oldVal instanceof \Carbon\Carbon || $oldVal instanceof \Illuminate\Support\Carbon) {
                $oldVal = $oldVal->format('Y-m-d');
            }

            if ((string) $oldVal !== (string) ($newVal ?? '')) {
                $changed[] = str_replace('_', ' ', $field);
            }
        }

        $changedStr = $changed ? implode(', ', $changed) : 'no changes';
        $ip         = $request->ip();

        SystemLog::create([
            'action'  => "Student {$studentNumber} ({$name}) record updated by staff — fields: {$changedStr} [IP: {$ip}]",
            'user_id' => $user->id,
            'role'    => $role,
        ]);
        return response()->json([
            'message' => 'Student updated successfully.',
            'student' => $student,
        ]);
    }

    /**
     * Update (or set) a student's active program. Archives old program enrollments.
     * Requires: new_program_id, reason. Optional: remarks.
     */
    public function updateProgram(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $role = $user->roles->first()?->name ?? $user->role ?? null;
        if (! in_array($role, ['staff'], true)) {
            return response()->json(['message' => 'Forbidden. Registrar staff only.'], 403);
        }

        $student = Student::find($id);
        if (! $student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $validated = $request->validate([
            'new_program_id' => ['required', 'integer', 'exists:programs,id'],
            'reason'         => ['required', 'string', 'max:100'],
            'remarks'        => ['nullable', 'string', 'max:500'],
        ]);

        $oldProgramId = $student->program_id;

        // If same program, no-op
        if ((int) $oldProgramId === (int) $validated['new_program_id']) {
            return response()->json(['message' => 'Student is already in this program.'], 422);
        }

        $archivedCount = 0;
        DB::transaction(function () use ($student, $validated, $oldProgramId, &$archivedCount, $user) {
            // Archive all active enrollments from the old program
            if ($oldProgramId) {
                $oldCurriculumSubjectIds = Curriculum::where('program_id', $oldProgramId)
                    ->pluck('subject_id')
                    ->toArray();

                $archivedCount = Enrollment::where('student_id', $student->student_id)
                    ->whereIn('subject_id', $oldCurriculumSubjectIds)
                    ->whereIn('status', ['enrolled'])
                    ->update(['status' => 'archived']);
            }

            // Update student's active program
            $student->program_id = $validated['new_program_id'];
            $student->save();

            // Log the program change
            ProgramChangeLog::create([
                'student_id'                  => $student->student_id,
                'old_program_id'              => $oldProgramId,
                'new_program_id'              => $validated['new_program_id'],
                'reason'                      => $validated['reason'],
                'remarks'                     => $validated['remarks'] ?? null,
                'changed_by'                  => $user->id,
                'affected_enrollments_archived' => $archivedCount,
            ]);

            SystemLog::create([
                'action'  => 'Student program changed',
                'user_id' => $user->id,
                'role'    => $user->roles->first()?->name ?? $user->role ?? null,
            ]);
        });

        $student->refresh();
        return response()->json([
            'message'           => 'Program updated successfully.',
            'student'           => $student->load('program'),
            'archived_count'    => $archivedCount,
        ]);
    }

    /**
     * List subjects under a specific program curriculum.
     * Optional query params: year_level (int), semester (1 or 2).
     */
    public function programSubjects(int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles(request()->user(), ['staff', 'admin'])) {
            return $err;
        }

        // Prerequisites come only from curriculum_prerequisites (#17).
        $query = Curriculum::with(['subject', 'prerequisites:id,code,title'])
            ->where('program_id', $id)
            ->orderBy('year_level')
            ->orderBy('semester');

        if ($yearLevel = request()->input('year_level')) {
            $query->where('year_level', (int) $yearLevel);
        }
        if ($semester = request()->input('semester')) {
            $semesterMap = ['1st' => 1, '2nd' => 2];
            $semInt = is_numeric($semester) ? (int) $semester : ($semesterMap[$semester] ?? null);
            if ($semInt) {
                $query->where('semester', $semInt);
            }
        }

        $curriculum = $query->get();

        // Every row stays (the curriculum page lists archived subjects too);
        // each subject says whether it is archived so pickers can leave it out (#68).
        $curriculum->each(fn ($row) => $row->subject?->append('archived'));

        return response()->json(['curriculum' => $curriculum]);
    }

    /**
     * Add subjects to a student's record for an explicitly chosen term.
     *
     * The rules live in App\Services\Enrollment and are shared with every other
     * path that can create an enrollment; this method only translates HTTP in
     * and out. The previous hand-rolled implementation was unreachable in
     * practice: its transaction closure referenced $passedSubjectIds without
     * importing it, so every call raised a TypeError and returned 500 without
     * enrolling anyone.
     */
    public function storeEnrollment(Request $request, int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }

        $student = Student::find($id);
        if (! $student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        if (! $student->program_id) {
            return response()->json(['message' => 'Student has no active program set. Please set a program first.'], 422);
        }

        $validated = $request->validate([
            'academic_year' => ['required', 'string', 'max:20'],
            'semester'      => ['required', 'string', 'max:20'],
            'status'        => ['nullable', 'string', 'max:20', 'in:enrolled,completed,dropped'],
            'year_level'    => ['required', 'integer', 'min:1', 'max:4'],
            'subject_ids'   => ['nullable', 'array'],
            'subject_ids.*' => ['integer', 'exists:subjects,id'],
        ], [
            'academic_year.required' => 'Academic year is required.',
            'semester.required'      => 'Semester is required.',
            'year_level.required'    => 'Year level is required.',
        ]);

        // Refuse an unrecognised semester instead of silently recording it as
        // 1st, which is what the old "?? 1" fallback did.
        $semester = EnrollmentTerm::normaliseSemester($validated['semester']);
        if ($semester === null) {
            return response()->json([
                'message' => 'Semester must be 1st or 2nd.',
                'errors'  => ['semester' => ['Semester must be 1st or 2nd.']],
            ], 422);
        }

        $term = new EnrollmentTerm(
            yearLevel: (int) $validated['year_level'],
            semester: $semester,
            academicYear: $validated['academic_year'],
        );

        // An empty selection means "enroll the whole term".
        $subjectIds = $validated['subject_ids'] ?? [];
        if (empty($subjectIds)) {
            $subjectIds = Curriculum::where('program_id', $student->program_id)
                ->where('year_level', $term->yearLevel)
                ->where('semester', $term->semester)
                ->pluck('subject_id')
                ->all();
        }

        if (empty($subjectIds)) {
            return response()->json([
                'message' => 'No subjects found for the selected program, year level, and semester.',
            ], 422);
        }

        $result = app(EnrollmentService::class)->enroll(
            $student,
            $term,
            $subjectIds,
            EnrollmentPolicy::manualEntry(),
            $request->user(),
        );

        if ($result->failed()) {
            return response()->json([
                'message' => 'Enrollment failed: the selection does not satisfy the curriculum rules.',
                'errors'  => ['subject_ids' => $result->errors()],
            ], 422);
        }

        $skipped = $result->skippedSubjectIds();

        if ($result->enrolledCount === 0) {
            return response()->json([
                'message' => 'All selected subjects are already actively enrolled or already completed.',
                'errors'  => ['subject_ids' => $result->outcome->skipReasons],
                'duplicate_subject_ids' => $skipped,
            ], 422);
        }

        SystemLog::create([
            'action'  => 'Enrollment added',
            'user_id' => $request->user()->id,
            'role'    => $request->user()->roles->first()?->name ?? $request->user()->role ?? null,
        ]);

        $message = "Enrollment added successfully. {$result->enrolledCount} subject(s) enrolled.";
        if (! empty($skipped)) {
            $message .= ' ' . count($skipped) . ' subject(s) were already enrolled or completed and were skipped.';
        }

        return response()->json([
            'message'               => $message,
            'enrolled_count'        => $result->enrolledCount,
            'skipped_duplicates'    => count($skipped),
            'duplicate_subject_ids' => $skipped,
        ], 201);
    }

    /**
     * Update an enrollment (staff/admin).
     */
    public function updateEnrollment(Request $request, int $id, int $enrollmentId): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }
        $enrollment = Enrollment::where('student_id', $id)->where('id', $enrollmentId)->first();
        if (! $enrollment) {
            return response()->json(['message' => 'Enrollment not found.'], 404);
        }
        $validated = $request->validate([
            'academic_year' => ['sometimes', 'required', 'string', 'max:20'],
            'semester' => ['sometimes', 'required', 'string', 'max:20'],
            'status' => ['nullable', 'string', 'max:20'],
        ]);

        // Statuses are the ones the rest of the system stores (#76), matched
        // without regard to case. Two older values still arrive from earlier
        // clients and have a clear meaning; "completed" does not (the final
        // status comes from the grade), so it is refused.
        if (isset($validated['status'])) {
            $status = self::ENROLLMENT_STATUS_ALIASES[strtolower($validated['status'])]
                ?? collect(self::ENROLLMENT_STATUSES)->first(fn ($s) => strcasecmp($s, $validated['status']) === 0);

            if ($status === null) {
                return response()->json([
                    'message' => 'The selected status is invalid.',
                    'errors'  => ['status' => ['Use one of: ' . implode(', ', self::ENROLLMENT_STATUSES) . '. A final status is recorded through the grade.']],
                ], 422);
            }
            $validated['status'] = $status;
        }

        // Moving an enrollment to another term used to bypass every rule, which
        // let a subject be relocated into a term where its prerequisites are not
        // satisfied. Re-check it before accepting the change.
        $newAcademicYear = $validated['academic_year'] ?? $enrollment->academic_year;
        $newSemester = EnrollmentTerm::normaliseSemester($validated['semester'] ?? $enrollment->semester);

        if ($newSemester === null) {
            return response()->json([
                'message' => 'Semester must be 1st or 2nd.',
                'errors'  => ['semester' => ['Semester must be 1st or 2nd.']],
            ], 422);
        }

        $termChanged = $newAcademicYear !== $enrollment->academic_year
            || $newSemester !== (int) $enrollment->semester;

        if ($termChanged) {
            $student = Student::where('student_id', $enrollment->student_id)->first();

            if ($student && $student->program_id) {
                $outcome = app(\App\Services\Enrollment\EnrollmentValidator::class)->validate(
                    $student,
                    new EnrollmentTerm(
                        yearLevel: (int) ($enrollment->year_level ?? 1),
                        semester: $newSemester,
                        academicYear: $newAcademicYear,
                    ),
                    [$enrollment->subject_id],
                    EnrollmentPolicy::termCorrection(),
                );

                if (! $outcome->isValid()) {
                    return response()->json([
                        'message' => 'Enrollment cannot be moved to that term.',
                        'errors'  => ['academic_year' => $outcome->errors],
                    ], 422);
                }
            }
        }

        // Store the semester the way every other path does ("1"/"2"), not as
        // typed ("1st"), so term grouping and checks still find it (#76).
        if (isset($validated['semester'])) {
            $validated['semester'] = (string) $newSemester;
        }

        $enrollment->update($validated);
        $enrollment->load('subject');
        SystemLog::create([
            'action' => 'Enrollment updated',
            'user_id' => $request->user()->id,
            'role' => $request->user()->roles->first()?->name ?? $request->user()->role ?? null,
        ]);
        return response()->json(['message' => 'Enrollment updated.', 'enrollment' => $enrollment]);
    }

    /**
     * Archive (soft-delete) a single subject enrollment.
     * - Checks for existing grade and warns frontend.
     * - Uses soft delete to preserve history.
     * - Writes enrollment audit log.
     * - Cleans up ProgramMapping if no more active subjects remain in that semester group.
     */
    public function destroyEnrollment(Request $request, int $id, int $enrollmentId): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }

        // Fetch only non-deleted enrollment belonging to this student
        $enrollment = Enrollment::where('student_id', $id)
            ->where('id', $enrollmentId)
            ->whereNull('deleted_at')
            ->first();

        if (! $enrollment) {
            return response()->json(['message' => 'Enrollment not found or already removed.'], 404);
        }

        $user   = $request->user();
        $reason = $request->input('reason', null);

        // Check if this enrollment has a grade with final status
        $grade = Grade::where('student_id', $id)
            ->where('subject_id', $enrollment->subject_id)
            ->where('academic_year', $enrollment->academic_year)
            ->where('semester', $enrollment->semester)
            ->first();

        $hasFinalStatus = false;
        if ($grade) {
            $finalStatuses = ['Passed', 'Failed', 'INC', 'Withdrawn', 'FDA', 'Credited'];
            if ($grade->status && in_array($grade->status, $finalStatuses)) {
                $hasFinalStatus = true;
            } elseif ($grade->grade_value !== null && $grade->grade_value > 0) {
                $hasFinalStatus = true;
            } elseif ($grade->remarks && in_array(strtoupper($grade->remarks), ['PASSED', 'FAILED', 'INC', 'WITHDRAWN', 'FDA', 'CREDITED'])) {
                $hasFinalStatus = true;
            }
        }

        // Block deletion if enrollment has a grade with final status
        if ($hasFinalStatus) {
            return response()->json([
                'message' => 'This enrollment already has a grade or final status. Use correction workflow instead of deleting finalized academic history.',
                'has_final_status' => true,
                'grade_value' => $grade?->grade_value,
                'grade_status' => $grade?->status ?? $grade?->remarks,
            ], 422);
        }

        // No final grade — allow cancellation
        if (!$reason) {
            return response()->json([
                'message' => 'A reason is required to cancel an enrollment.',
                'requires_reason' => true,
            ], 422);
        }

        DB::transaction(function () use ($enrollment, $user, $reason, $grade) {
            $oldStatus = $enrollment->status;

            // Update status to Cancelled and soft-delete
            $enrollment->status = 'Cancelled';
            $enrollment->deleted_by   = $user->id;
            $enrollment->delete_reason = $reason;
            $enrollment->save();
            $enrollment->delete(); // triggers SoftDelete

            // Also remove the placeholder grade if it exists and has no value
            if ($grade && $grade->grade_value === null && (!$grade->status || $grade->status === 'Enrolled')) {
                $grade->delete();
            }

            // Write audit log
            EnrollmentAuditLog::create([
                'student_id'    => $enrollment->student_id,
                'enrollment_id' => $enrollment->id,
                'subject_id'    => $enrollment->subject_id,
                'academic_year' => $enrollment->academic_year,
                'semester'      => $enrollment->semester,
                'old_status'    => $oldStatus,
                'new_status'    => 'Cancelled',
                'changed_by'    => $user->id,
                'action'        => 'cancelled',
                'reason'        => $reason,
                'had_grade'     => !is_null($grade),
                'user_role'     => $user->roles->first()?->name ?? $user->role ?? null,
            ]);

            // Clean up ProgramMapping if last active subject in semester group
            $remainingActive = Enrollment::where('student_id', $enrollment->student_id)
                ->where('academic_year', $enrollment->academic_year)
                ->where('semester', $enrollment->semester)
                ->whereNull('deleted_at')
                ->whereNotIn('status', ['archived', 'Cancelled'])
                ->count();

            if ($remainingActive === 0) {
                ProgramMapping::where('student_id', $enrollment->student_id)
                    ->where('academic_year', $enrollment->academic_year)
                    ->where('semester', $enrollment->semester)
                    ->update(['status' => 'archived']);
            }
        });

        SystemLog::create([
            'action'  => 'Enrollment cancelled',
            'user_id' => $user->id,
            'role'    => $user->roles->first()?->name ?? $user->role ?? null,
        ]);

        return response()->json([
            'message'  => 'Enrollment cancelled successfully.',
            'archived' => true,
        ]);
    }

    /**
     * Store grade for a student. Required: subject_id, academic_year, semester. Optional: grade_value, remarks.
     */
    public function storeGrade(Request $request, int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }
        $student = Student::find($id);
        if (! $student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }
        $validated = $request->validate([
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'academic_year' => ['required', 'string', 'max:20'],
            'semester' => ['required', 'string', 'max:20'],
            'grade_value' => ['nullable', 'numeric', 'min:0', 'max:5.00'],
            'remarks' => ['nullable', 'string', 'max:50'],
        ], [
            'subject_id.required' => 'Subject is required.',
            'academic_year.required' => 'Academic year is required.',
            'semester.required' => 'Semester is required.',
        ]);
        $validated['student_id'] = $student->student_id;
        if (isset($validated['grade_value'])) {
            $validated['grade_value'] = round((float) $validated['grade_value'], 2);
        }
        $exists = Grade::where('student_id', $student->student_id)
            ->where('subject_id', $validated['subject_id'])
            ->where('academic_year', $validated['academic_year'])
            ->where('semester', $validated['semester'])
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'A grade already exists for this subject, academic year, and semester.', 'errors' => ['subject_id' => ['Duplicate grade.']]], 422);
        }
        // Every rule that reads grades keys off the status, so derive it here
        // the same way bulk grade entry does.
        $progression = app(AcademicProgressionService::class);
        $gradeValue = $validated['grade_value'] ?? null;
        $validated['status'] = $progression->autoStatusFromGrade($gradeValue, null);
        $validated['remarks'] = $validated['remarks'] ?? $progression->autoGenerateRemarks($gradeValue, $validated['status']);
        $grade = Grade::create($validated);
        $grade->load('subject');
        
        // Recalculate GWA
        $student = Student::find($id);
        if ($student) {
            $standingService = app(\App\Services\AcademicStandingService::class);
            $standingService->recomputeAndCacheOverallGwa($student);
        }

        SystemLog::create([
            'action' => 'Grade added',
            'user_id' => $request->user()->id,
            'role' => $request->user()->roles->first()?->name ?? $request->user()->role ?? null,
        ]);
        return response()->json(['message' => 'Grade added.', 'grade' => $grade], 201);
    }

    /**
     * Update a grade (staff/admin).
     */
    public function updateGrade(Request $request, int $id, int $gradeId): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }
        $grade = Grade::where('student_id', $id)->where('id', $gradeId)->first();
        if (! $grade) {
            return response()->json(['message' => 'Grade not found.'], 404);
        }
        $validated = $request->validate([
            'academic_year' => ['sometimes', 'required', 'string', 'max:20'],
            'semester' => ['sometimes', 'required', 'string', 'max:20'],
            'grade_value' => ['nullable', 'numeric', 'min:0', 'max:5.00'],
            'status' => ['nullable', 'string', 'in:' . implode(',', self::GRADE_STATUSES)],
            'remarks' => ['nullable', 'string', 'max:50'],
            'supporting_document_reference' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $role = $this->userRole($user);
        $changesGrade = array_intersect_key($validated, array_flip(['grade_value', 'status', 'remarks'])) !== [];

        $error = DB::transaction(function () use ($grade, $validated, $user, $role, $changesGrade) {
            // Grade change first: applyGradeChange refuses before writing
            // anything, so a refused change leaves the term untouched too.
            if ($changesGrade) {
                // Unspecified fields keep their current values; a new grade
                // value with no explicit status re-derives the status.
                $error = $this->applyGradeChange($grade, [
                    'grade_value' => array_key_exists('grade_value', $validated) ? $validated['grade_value'] : $grade->grade_value,
                    'status' => $validated['status'] ?? (array_key_exists('grade_value', $validated) ? null : $grade->status),
                    'remarks' => $validated['remarks'] ?? null,
                    'supporting_document_reference' => $validated['supporting_document_reference'] ?? $grade->supporting_document_reference,
                ], $user, $role);

                if ($error !== null) {
                    return $error;
                }
            }

            $termFields = array_intersect_key($validated, array_flip(['academic_year', 'semester']));
            if ($termFields !== []) {
                $grade->update($termFields);
            }

            return null;
        });

        if ($error !== null) {
            return response()->json(['message' => $error, 'errors' => ['status' => [$error]]], 422);
        }
        $grade->refresh()->load('subject');
        
        // Recalculate GWA
        $student = Student::find($id);
        if ($student) {
            $standingService = app(\App\Services\AcademicStandingService::class);
            $standingService->recomputeAndCacheOverallGwa($student);
        }

        SystemLog::create([
            'action' => 'Grade updated',
            'user_id' => $request->user()->id,
            'role' => $request->user()->roles->first()?->name ?? $request->user()->role ?? null,
        ]);
        return response()->json(['message' => 'Grade updated.', 'grade' => $grade]);
    }

    /**
     * Delete a grade (staff/admin).
     */
    public function destroyGrade(Request $request, int $id, int $gradeId): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }
        $grade = Grade::where('student_id', $id)->where('id', $gradeId)->first();
        if (! $grade) {
            return response()->json(['message' => 'Grade not found.'], 404);
        }
        $grade->delete();

        // Recalculate GWA
        $student = Student::find($id);
        if ($student) {
            $standingService = app(\App\Services\AcademicStandingService::class);
            $standingService->recomputeAndCacheOverallGwa($student);
        }

        SystemLog::create([
            'action' => 'Grade removed',
            'user_id' => $request->user()->id,
            'role' => $request->user()->roles->first()?->name ?? $request->user()->role ?? null,
        ]);
        return response()->json(['message' => 'Grade removed.']);
    }

    /*
     *  Archive a student
    */
    public function archiveStudent(Request $request, int $id): JsonResponse
    {
        try {
            if ($err = $this->requireAuth()) {
                return $err;
            }
            if ($err = $this->requireRoles($request->user(), ['staff'])) {
                return $err;
            }
            $student = Student::find($id);
            if (! $student) {
                return response()->json(['message' => 'Student not found.'], 404);
            }
            $archiveRecord = ArchiveRecord::create([
                'student_id' => $student->student_id,
                'record_type' => $request->input('record_type'),
                'cabinet_no' => $request->input('cabinet_no'),
                'shelf_no' => $request->input('shelf_no'),
                'folder_code' => $request->input('folder_code'),
                'document_status' => $request->input('document_status'),
            ]);
            if (!$archiveRecord) {
                return response()->json(['message' => 'Failed to create archive record.'], 500);
            }
            SystemLog::create([
                'action' => 'Student archived',
                'user_id' => $request->user()->id,
                'role' => $request->user()->roles->first()?->name ?? $request->user()->role ?? null,
            ]);
            return response()->json(['message' => 'Student archived successfully.']);
        } catch (\Exception $e) {
            Log::error('Failed to archive student: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to archive student.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Academic Progression Endpoints
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * GET /api/staff/students/{id}/academic-progress
     * Returns full academic progression state for a student.
     */
    public function academicProgress(int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles(request()->user(), ['staff', 'admin'])) {
            return $err;
        }

        $student = Student::with('program')->find($id);
        if (! $student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $service = app(AcademicProgressionService::class);
        $progress = $service->getAcademicProgress($student);

        return response()->json($progress);
    }

    /**
     * GET /api/staff/students/{id}/academic-summary
     * Returns full academic summary including GWA, GPAs, and Honors eligibility.
     */
    public function academicSummary(int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles(request()->user(), ['staff', 'admin'])) {
            return $err;
        }

        $student = Student::find($id);
        if (! $student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $student->load('program');

        $standingService = app(\App\Services\AcademicStandingService::class);
        $progressionService = app(\App\Services\AcademicProgressionService::class);

        $summary = $standingService->getAcademicSummary($student);
        $roadmapData = $progressionService->getCurriculumRoadmap($student);

        // Compute Notifications (similar to student side, just in case staff needs it)
        $notifications = [];
        
        if ($summary['latin_honors']['eligible']) {
            $notifications[] = [
                'type' => 'success',
                'message' => 'Student is currently eligible for Latin Honors: ' . $summary['latin_honors']['honor']
            ];
        }
        
        if ($roadmapData['failed_subjects_count'] > 0) {
            $notifications[] = [
                'type' => 'warning',
                'message' => 'Student has ' . $roadmapData['failed_subjects_count'] . ' failed subject(s) that require a retake.'
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
            'notifications' => $notifications
        ]);
    }

    /**
     * POST /api/staff/students/{id}/enrollments/add-next-term
     * Add enrollment for the next allowed term. Backend computes everything.
     */
    public function addNextTerm(Request $request, int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }

        $student = Student::find($id);
        if (! $student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        if (! $student->program_id) {
            return response()->json(['message' => 'Student has no active program. Set a program first.'], 422);
        }

        $validated = $request->validate([
            // Regular next-term subjects (optional if only retaking)
            'subject_ids'     => ['nullable', 'array'],
            'subject_ids.*'   => ['integer', 'exists:subjects,id'],
            // Retake subjects (previously Failed/Withdrawn/FDA)
            'retake_subject_ids'   => ['nullable', 'array'],
            'retake_subject_ids.*' => ['integer', 'exists:subjects,id'],
        ]);

        $subjectIds      = $validated['subject_ids'] ?? [];
        $retakeSubjectIds = $validated['retake_subject_ids'] ?? [];

        // At least one subject must be selected (regular or retake)
        if (empty($subjectIds) && empty($retakeSubjectIds)) {
            return response()->json([
                'message' => 'Enrollment validation failed.',
                'errors'  => ['validation' => ['Select at least one regular or retake subject to enroll.']],
            ], 422);
        }

        $service = app(AcademicProgressionService::class);
        $result  = $service->validateAddNextTerm($student, $subjectIds, $retakeSubjectIds);

        if (!$result['valid']) {
            return response()->json([
                'message' => 'Enrollment validation failed.',
                'errors'  => ['validation' => $result['errors']],
            ], 422);
        }

        // ── Academic load validation ──────────────────────────────────────────
        // Must run after subject/retake validation so we work with the clean
        // validated ID lists, not the raw frontend input.
        $allSelectedIds    = array_merge($result['data']['subject_ids'], $result['data']['retake_ids']);
        $loadService       = app(\App\Services\AcademicLoadValidationService::class);
        $validatedNextTerm = [
            'can_add'       => true,
            'year_level'    => $result['data']['year_level'],
            'semester'      => (int) $result['data']['semester'],
            'academic_year' => $result['data']['academic_year'],
        ];
        $maxEligible = $loadService->computeMaxEligibleUnits($student, $validatedNextTerm);
        $loadCheck   = $loadService->validate($allSelectedIds, $maxEligible);

        if (!$loadCheck['is_valid_load']) {
            return response()->json([
                'message'         => 'Enrollment failed: ' . $loadCheck['message'],
                'errors'          => ['load' => [$loadCheck['message']]],
                'load_validation' => $loadCheck,
            ], 422);
        }
        // ─────────────────────────────────────────────────────────────────────

        // Residency is enforced by validateAddNextTerm() above via
        // computeNextAllowedTerm(). No separate residency call is needed here.

        $data          = $result['data'];
        $user          = $request->user();
        $role          = $user->roles->first()?->name ?? $user->role ?? null;
        $enrolledCount = 0;
        $retakeCount   = 0;

        // Persistence is shared with every other enrollment path, so the
        // Enrollment / Grade / audit-log trio is always written together.
        $term = new EnrollmentTerm(
            yearLevel: (int) $data['year_level'],
            semester: (int) $data['semester'],
            academicYear: $data['academic_year'],
        );

        $enrollmentService = app(EnrollmentService::class);

        DB::transaction(function () use ($enrollmentService, $student, $term, $data, $user, &$enrolledCount, &$retakeCount) {
            $enrolledCount = $enrollmentService->persistMany(
                $student, $term, $data['subject_ids'], $user, false
            );

            $retakeCount = $enrollmentService->persistMany(
                $student, $term, $data['retake_ids'], $user, true
            );

            $enrollmentService->upsertProgramMapping($student, $term);
        });

        $total = $enrolledCount + $retakeCount;
        
        $studentName = trim($student->first_name . ' ' . $student->last_name);
        $studentIdent = $student->student_number ? "{$student->student_number} ({$studentName})" : $studentName;

        SystemLog::create([
            'action'  => "Added {$enrolledCount} enrollment(s) and {$retakeCount} retake(s) for student {$studentIdent} - Year {$data['year_level']} Sem {$data['semester']} A.Y. {$data['academic_year']}",
            'user_id' => $user->id,
            'role'    => $role,
        ]);

        $student->refresh();
        $progress = $service->getAcademicProgress($student);

        return response()->json([
            'message'        => "{$total} subject(s) enrolled successfully for Year {$data['year_level']}, Semester {$data['semester']}, A.Y. {$data['academic_year']} ({$enrolledCount} new, {$retakeCount} retake).",
            'enrolled_count' => $enrolledCount,
            'retake_count'   => $retakeCount,
            'progress'       => $progress,
        ], 201);
    }


    /**
     * PUT /api/staff/students/{id}/grades/bulk-update
     * Bulk update grades for enrolled subjects.
     */
    public function bulkUpdateGrades(Request $request, int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }

        $student = Student::find($id);
        if (! $student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $validated = $request->validate([
            'grades'                                => ['required', 'array', 'min:1'],
            'grades.*.grade_id'                     => ['required', 'integer', 'exists:grades,id'],
            'grades.*.grade_value'                  => ['nullable', 'numeric', 'min:0', 'max:5.00'],
            'grades.*.status'                       => ['nullable', 'string', 'in:' . implode(',', self::GRADE_STATUSES)],
            'grades.*.remarks'                      => ['nullable', 'string', 'max:50'],
            'grades.*.supporting_document_reference' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $role = $user->roles->first()?->name ?? $user->role ?? null;
        $errors = [];
        $updatedCount = 0;

        DB::transaction(function () use ($validated, $student, $user, $role, &$errors, &$updatedCount) {
            foreach ($validated['grades'] as $gradeData) {
                $grade = Grade::where('id', $gradeData['grade_id'])
                    ->where('student_id', $student->student_id)
                    ->first();

                if (!$grade) {
                    $errors[] = "Grade #{$gradeData['grade_id']} not found for this student.";
                    continue;
                }

                if ($error = $this->applyGradeChange($grade, $gradeData, $user, $role)) {
                    $errors[] = $error;
                    continue;
                }

                $updatedCount++;
            }
        });

        if (!empty($errors) && $updatedCount === 0) {
            return response()->json([
                'message' => 'Grade update failed.',
                'errors'  => ['validation' => $errors],
            ], 422);
        }
        $studentName = trim($student->first_name . ' ' . $student->last_name);
        $studentIdent = $student->student_number ? "{$student->student_number} ({$studentName})" : $studentName;

        SystemLog::create([
            'action'  => "Bulk updated {$updatedCount} grade(s) for student {$studentIdent}",
            'user_id' => $user->id,
            'role'    => $role,
        ]);

        try {
            // Return updated progress
            $student->refresh();
            
            // Recalculate GWA
            $standingService = app(\App\Services\AcademicStandingService::class);
            $standingService->recomputeAndCacheOverallGwa($student);

            $service = app(AcademicProgressionService::class);
            $progress = $service->getAcademicProgress($student);

            return response()->json([
                'message'       => "{$updatedCount} grade(s) updated successfully.",
                'updated_count' => $updatedCount,
                'errors'        => $errors,
                'progress'      => $progress,
            ]);
        } catch (\Exception $e) {
            \Log::error("bulkUpdateGrades Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'message' => 'Exception after saving grades: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Apply one grade change the way every grade-writing endpoint must: derive
     * the status and remarks from the grade value, refuse Credited without a
     * supporting document, keep the enrollment row in step, and write the
     * audit trail. Callers own the surrounding transaction.
     *
     * @param array{grade_value?: mixed, status?: ?string, remarks?: ?string, supporting_document_reference?: ?string} $data
     * @return string|null An error message, or null once the change is saved.
     */
    private function applyGradeChange(Grade $grade, array $data, User $user, ?string $role): ?string
    {
        $service = app(AcademicProgressionService::class);

        $gradeValue = isset($data['grade_value']) && $data['grade_value'] !== ''
            ? round((float) $data['grade_value'], 2)
            : null;

        $newStatus = $service->autoStatusFromGrade($gradeValue, $data['status'] ?? null);

        $remarks = !empty($data['remarks'])
            ? $data['remarks']
            : $service->autoGenerateRemarks($gradeValue, $newStatus);

        if ($newStatus === 'Credited' && empty($data['supporting_document_reference'])) {
            return "Grade #{$grade->id}: Credited status requires a supporting document reference.";
        }

        // Captured before the update: once saved, Eloquent re-syncs the
        // "original" attributes, so reading them afterwards returns the new
        // values and every audit row would show old status = new status.
        $oldStatus = $grade->status;
        $oldValue = json_encode([
            'grade_value' => $grade->grade_value,
            'status'      => $grade->status,
            'remarks'     => $grade->remarks,
        ]);

        $convertedFrom = null;
        $convertedAt = null;
        if ($oldStatus === 'INC' && $newStatus === 'Passed') {
            $convertedFrom = 'INC';
            $convertedAt = now();
        }

        $grade->update([
            'grade_value'                   => $gradeValue,
            'status'                        => $newStatus,
            'remarks'                       => $remarks,
            'supporting_document_reference' => $data['supporting_document_reference'] ?? $grade->supporting_document_reference,
            'converted_from_status'         => $convertedFrom ?? $grade->converted_from_status,
            'converted_at'                  => $convertedAt ?? $grade->converted_at,
        ]);

        if ($grade->enrollment_id) {
            Enrollment::where('id', $grade->enrollment_id)->update(['status' => $newStatus]);
        } else {
            Enrollment::where('student_id', $grade->student_id)
                ->where('subject_id', $grade->subject_id)
                ->where('academic_year', $grade->academic_year)
                ->where('semester', $grade->semester)
                ->whereNull('deleted_at')
                ->update(['status' => $newStatus]);
        }

        $action = 'grade_updated';
        if ($convertedFrom === 'INC') {
            $action = 'inc_to_passed';
        } elseif ($newStatus === 'Credited') {
            $action = 'marked_credited';
        }

        EnrollmentAuditLog::create([
            'student_id'                    => $grade->student_id,
            'enrollment_id'                 => $grade->enrollment_id ?? 0,
            'subject_id'                    => $grade->subject_id,
            'academic_year'                 => $grade->academic_year,
            'semester'                      => $grade->semester,
            'old_status'                    => $oldStatus,
            'new_status'                    => $newStatus,
            'changed_by'                    => $user->id,
            'action'                        => $action,
            'reason'                        => null,
            'had_grade'                     => true,
            'old_value'                     => $oldValue,
            'new_value'                     => json_encode([
                'grade_value' => $gradeValue,
                'status'      => $newStatus,
                'remarks'     => $remarks,
            ]),
            'supporting_document_reference' => $data['supporting_document_reference'] ?? null,
            'user_role'                     => $role,
        ]);

        return null;
    }
}
