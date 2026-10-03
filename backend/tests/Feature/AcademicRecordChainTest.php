<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\RecordRequest;
use App\Models\Student;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\OfficialTranscriptExportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * D2 / E4: one student carried through the whole record, across every module
 * boundary, using only the HTTP endpoints the UI calls:
 *
 *   create student (with first-term subjects) → student logs in → grades
 *   entered → next term enrolled against a now-passed prerequisite → academic
 *   standing → transcript requested, approved, downloaded → released.
 */
class AcademicRecordChainTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private Program $program;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->program = $this->makeProgram();

        // Y1S1: A, B      Y1S2: C (needs A), D (needs B)
        $this->makeCurriculum($this->program, [
            'A' => [1, 1],
            'B' => [1, 1],
            'C' => [1, 2],
            'D' => [1, 2],
        ], ['C' => ['A'], 'D' => ['B']]);

        // Keep the "current term" setting and the enrollment date in the same
        // academic year, as they are for a genuinely new student.
        SystemSetting::setValue('academic_year', '2026-2027');

        $this->staff = $this->makeUser('staff');
    }

    public function test_enrollment_to_grades_to_transcript(): void
    {
        // ── 1. Registrar creates the student with first-term subjects ────────
        Sanctum::actingAs($this->staff, ['*']);

        $created = $this->postJson('/api/staff/students', [
            'student_number'  => '260100',
            'first_name'      => 'Ana',
            'last_name'       => 'Reyes',
            'date_of_birth'   => '2006-02-02',
            'email'           => 'ana@tmcc.test',
            'sex'             => 'F',
            'enrollment_date' => '2026-06-01',
            'program_id'      => $this->program->id,
            'subject_ids'     => $this->idsFor(['A', 'B']),
            'record_type'     => 'Form 137',
            'cabinet_no'      => 'C1',
            'shelf_no'        => 'S1',
            'folder_code'     => 'F1',
            'document_status' => 'Complete',
        ])->assertCreated();

        $student = Student::findOrFail($created->json('student.student_id'));
        $credentials = $created->json('account');

        $this->assertSame(2, $student->enrollments()->where('academic_year', '2026-2027')->count());

        // ── 2. The student can log in with the issued credentials ───────────
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', $credentials)
            ->assertOk()
            ->assertJsonPath('user.roles.0.name', 'student');
        $studentUser = User::where('username', '260100')->firstOrFail();

        // ── 3. First-term grades; the next term only opens after this ────────
        Sanctum::actingAs($this->staff, ['*']);
        $this->assertFalse($this->academicProgress($student)['next_allowed_term']['can_add']);

        $this->submitGrades($student, ['A' => 1.25, 'B' => 1.50])->assertOk();
        $this->assertSame('1.38', $student->fresh()->GPA);

        // ── 4. Next term, against a prerequisite that is now passed ─────────
        $progress = $this->academicProgress($student);
        $this->assertSame(2, $progress['next_allowed_term']['semester']);
        $this->assertTrue($this->availableSubject($progress, 'C')['eligible']);

        $this->enrollNextTerm($student, ['C', 'D'])->assertCreated();
        $this->assertSame(2, $student->enrollments()->where('semester', 2)->where('academic_year', '2026-2027')->count());

        // ── 5. The student sees their standing ─────────────────────────────
        Sanctum::actingAs($studentUser, ['*']);
        $summary = $this->getJson('/api/student/academic-summary')->assertOk()->json('summary');

        $this->assertSame(1.38, $summary['overall_gwa']);
        $this->assertTrue($summary['terms'][0]['deans_list']['eligible']);

        // ── 6. Transcript: request → approve → download → release ───────────
        $requestId = $this->postJson('/api/student/record-requests', ['record_type' => 'transcript'])
            ->assertCreated()
            ->json('record_request.id');

        Sanctum::actingAs($this->staff, ['*']);
        $this->patchJson("/api/staff/requests/{$requestId}/approve", [
            'appointment_at' => Carbon::now('Asia/Manila')->addDays(2)->setTime(10, 0)->toIso8601String(),
        ])->assertOk();

        Sanctum::actingAs($studentUser, ['*']);
        $pdf = $this->get("/api/student/record-requests/{$requestId}/transcript")->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->streamedContent());

        $html = (new ReflectionMethod(OfficialTranscriptExportService::class, 'buildHtml'))
            ->invoke(app(OfficialTranscriptExportService::class), $student->fresh(['program', 'grades.subject']), 'TOR', 'now');
        $this->assertStringContainsString('REYES, ANA', $html);
        $this->assertStringContainsString('Subject A', $html);
        $this->assertStringContainsString('Subject C', $html);

        Sanctum::actingAs($this->staff, ['*']);
        $this->putJson("/api/staff/requests/{$requestId}/release")->assertOk();

        $this->assertSame(RecordRequest::STATUS_RELEASED, RecordRequest::findOrFail($requestId)->status);
        $this->assertDatabaseHas('record_transactions', [
            'student_id'       => $student->student_id,
            'transaction_type' => 'document_release',
        ]);
    }
}
