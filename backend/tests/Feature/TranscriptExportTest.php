<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\RecordRequest;
use App\Models\Student;
use App\Models\User;
use App\Services\OfficialTranscriptExportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * A6: the official transcript PDF on all three routes that serve it.
 * E2: a corrected grade appears on the next transcript — nothing is stored or
 * cached between downloads.
 */
class TranscriptExportTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private Program $program;
    private Student $student;
    private User $studentUser;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->program = $this->makeProgram();
        $this->makeCurriculum($this->program, ['A' => [1, 1], 'B' => [1, 1]]);

        $this->staff = $this->makeUser('staff');
        $this->studentUser = $this->makeUser('student', '2026-0001');
        $this->student = $this->makeStudent($this->program, [], $this->studentUser);
    }

    private function assertPdf($response): void
    {
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $response->streamedContent());
    }

    /** The HTML the PDF is rendered from, so its content can be asserted. */
    private function transcriptHtml(): string
    {
        $method = new ReflectionMethod(OfficialTranscriptExportService::class, 'buildHtml');

        return $method->invoke(
            app(OfficialTranscriptExportService::class),
            $this->student->fresh(['program', 'grades.subject']),
            'TOR-TEST',
            'now'
        );
    }

    /** The grade cell of $code's row: code, title, then grade. */
    private function gradeCell(string $html, string $code): ?string
    {
        $pattern = '#>' . preg_quote($code, '#') . '</td>\s*<td[^>]*>[^<]*</td>\s*<td[^>]*>([^<]*)</td>#';

        return preg_match($pattern, $html, $m) ? $m[1] : null;
    }

    private function studentRequest(string $status, string $type = 'transcript'): RecordRequest
    {
        return RecordRequest::create([
            'student_id'     => $this->student->student_id,
            'record_type'    => $type,
            'status'         => $status,
            'requested_at'   => now(),
            'copies'         => 1,
            'appointment_at' => $status === 'pending' ? null : Carbon::now()->addDay(),
        ]);
    }

    // ------------------------------------------------- staff: by student

    public function test_staff_download_a_students_transcript(): void
    {
        $this->recordGrade($this->student, 'A', '2026-2027', 1, 1.50, 'Passed');
        Sanctum::actingAs($this->staff, ['*']);

        $response = $this->get("/api/staff/students/{$this->student->student_id}/transcript");

        $this->assertPdf($response);
        $this->assertStringContainsString('OFFICIAL_TRANSCRIPT_OF_RECORD_2026-0001_', $response->headers->get('Content-Disposition'));
    }

    public function test_admin_can_download_a_transcript(): void
    {
        Sanctum::actingAs($this->makeUser('admin'), ['*']);

        $this->assertPdf($this->get("/api/staff/students/{$this->student->student_id}/transcript"));
    }

    public function test_students_cannot_use_the_staff_transcript_route(): void
    {
        Sanctum::actingAs($this->studentUser, ['*']);

        $this->getJson("/api/staff/students/{$this->student->student_id}/transcript")->assertForbidden();
    }

    public function test_transcript_for_unknown_student_returns_404(): void
    {
        Sanctum::actingAs($this->staff, ['*']);

        $this->getJson('/api/staff/students/999999/transcript')->assertNotFound();
    }

    // ------------------------------------------------- student: own request

    public function test_student_downloads_their_transcript_once_approved(): void
    {
        $request = $this->studentRequest('approved');
        Sanctum::actingAs($this->studentUser, ['*']);

        $this->assertPdf($this->get("/api/student/record-requests/{$request->id}/transcript"));
    }

    public function test_student_cannot_download_before_approval(): void
    {
        $request = $this->studentRequest('pending');
        Sanctum::actingAs($this->studentUser, ['*']);

        $this->getJson("/api/student/record-requests/{$request->id}/transcript")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Transcript is only available once your request has been approved.');
    }

    public function test_student_cannot_download_another_students_transcript(): void
    {
        $otherUser = $this->makeUser('student', '2026-0002');
        $this->makeStudent($this->program, ['student_number' => '2026-0002', 'email' => 'other@tmcc.test'], $otherUser);
        $request = $this->studentRequest('approved');

        Sanctum::actingAs($otherUser, ['*']);

        $this->getJson("/api/student/record-requests/{$request->id}/transcript")->assertNotFound();
    }

    // ------------------------------------------- staff: released request

    public function test_staff_download_the_transcript_for_a_released_request(): void
    {
        $request = $this->studentRequest('released');
        Sanctum::actingAs($this->staff, ['*']);

        $this->assertPdf($this->get("/api/staff/requests/{$request->id}/transcript-template"));
    }

    /**
     * #14 (audit §5.1 item 8): the download used to return 500 when the XLSX
     * template was missing, although the output is a Dompdf PDF that never
     * reads it. The template is in the repository, so the test above cannot
     * tell; here the public folder is empty.
     */
    public function test_released_transcript_downloads_without_the_unused_xlsx_template(): void
    {
        $public = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'asrms-public-' . uniqid();
        mkdir($public);

        try {
            $this->app->usePublicPath($public);
            $this->assertFileDoesNotExist(public_path('assets/templates/OFFICIAL TRANSCRIPT OF RECORD - template.xlsx'));

            $request = $this->studentRequest('released');
            Sanctum::actingAs($this->staff, ['*']);

            $this->assertPdf($this->get("/api/staff/requests/{$request->id}/transcript-template"));
        } finally {
            @rmdir($public);
        }
    }

    public function test_request_transcript_waits_for_release(): void
    {
        $request = $this->studentRequest('approved');
        Sanctum::actingAs($this->staff, ['*']);

        $this->getJson("/api/staff/requests/{$request->id}/transcript-template")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Transcript can only be downloaded after release.');
    }

    public function test_request_transcript_is_only_for_transcript_requests(): void
    {
        $request = $this->studentRequest('released', 'copy_of_grades');
        Sanctum::actingAs($this->staff, ['*']);

        $this->getJson("/api/staff/requests/{$request->id}/transcript-template")->assertStatus(422);
    }

    // ------------------------------------------------------------ content

    public function test_transcript_lists_every_graded_subject(): void
    {
        $this->recordGrade($this->student, 'A', '2026-2027', 1, 1.75, 'Passed');
        // Bulk grade entry writes the remark alongside the status.
        $this->recordGrade($this->student, 'B', '2026-2027', 1, null, 'INC')->update(['remarks' => 'INC']);

        $html = $this->transcriptHtml();

        $this->assertStringContainsString('DELA CRUZ, JUAN', $html);
        $this->assertSame('1.75', $this->gradeCell($html, 'A'));
        $this->assertSame('INC', $this->gradeCell($html, 'B'));
    }

    public function test_a_grade_without_a_value_or_remark_shows_its_status(): void
    {
        $this->recordGrade($this->student, 'A', '2026-2027', 1, null, 'INC');
        $this->recordGrade($this->student, 'B', '2026-2027', 1, null, 'Enrolled');

        $html = $this->transcriptHtml();

        $this->assertSame('INC', $this->gradeCell($html, 'A'));
        $this->assertSame('', $this->gradeCell($html, 'B'));
    }

    public function test_transcript_escapes_record_data(): void
    {
        $this->student->update(['address' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $this->transcriptHtml());
    }

    // ------------------------------------------------ E2: never stale

    public function test_corrected_grade_appears_on_the_next_transcript(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->staff, ['*']);
        $this->enrollNextTerm($this->student, ['A', 'B'])->assertCreated();
        $this->submitGrades($this->student, ['A' => 1.00, 'B' => 3.00])->assertOk();

        $this->assertPdf($this->get("/api/staff/students/{$this->student->student_id}/transcript"));
        $this->assertSame('3.00', $this->gradeCell($this->transcriptHtml(), 'B'));

        $this->submitGrades($this->student, ['B' => 1.25])->assertOk();

        $this->assertSame('1.25', $this->gradeCell($this->transcriptHtml(), 'B'));
        $this->assertPdf($this->get("/api/staff/students/{$this->student->student_id}/transcript"));

        // Generated on demand: no transcript file is ever written.
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
