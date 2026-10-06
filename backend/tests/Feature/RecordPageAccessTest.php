<?php

namespace Tests\Feature;

use App\Models\ArchiveRecord;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #57: the Manage Records record page reads a student, their academic summary
 * (GWA, awards, roadmap), their documents and the transcript. The registrar
 * and admins get the same data; admins can't write; students are refused.
 */
class RecordPageAccessTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private User $staff;
    private User $admin;
    private Student $student;
    private StudentDocument $document;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seedRoles();
        $this->staff = $this->makeUser('staff');
        $this->admin = $this->makeUser('admin');

        $program = $this->makeProgram('BSTM', 'BS Tourism Management');
        $this->makeCurriculum($program, ['GEC4' => [1, 1], 'TPC1' => [1, 1], 'TPC2' => [1, 2]]);
        $this->student = $this->makeStudent($program, ['student_number' => '260001'], $this->makeUser('student', '260001'));
        $this->recordGrade($this->student, 'GEC4', '2026-2027', 1, 1.25, 'Passed');
        $this->recordGrade($this->student, 'TPC1', '2026-2027', 1, 1.5, 'Passed');
        ArchiveRecord::create([
            'student_id' => $this->student->student_id, 'record_type' => 'Form 137', 'cabinet_no' => 'C1',
            'shelf_no' => 'S2', 'folder_code' => 'F-260001', 'document_status' => 'Complete',
        ]);

        $path = "student-documents/{$this->student->student_id}/form137.pdf";
        Storage::disk('local')->put($path, '%PDF-1.4 test');
        $this->document = StudentDocument::create([
            'student_id' => $this->student->student_id, 'uploaded_by' => $this->staff->id, 'document_type' => 'Form 137',
            'file_path' => $path, 'original_name' => 'form137.pdf', 'mime' => 'application/pdf', 'size' => 13,
        ]);
    }

    /** What the record page loads, as JSON, for the signed-in user. */
    private function recordPageReads(): array
    {
        $id = $this->student->student_id;

        return [
            'student'   => $this->getJson("/api/staff/students/{$id}")->assertOk()->json(),
            'summary'   => $this->getJson("/api/staff/students/{$id}/academic-summary")->assertOk()->json(),
            'documents' => $this->getJson("/api/staff/students/{$id}/documents")->assertOk()->json(),
        ];
    }

    public function test_registrar_and_admin_read_the_same_record(): void
    {
        Sanctum::actingAs($this->staff, ['*']);
        $asRegistrar = $this->recordPageReads();

        Sanctum::actingAs($this->admin, ['*']);
        $asAdmin = $this->recordPageReads();

        $this->assertSame($asRegistrar, $asAdmin);

        // What the four sections show.
        $this->assertSame('260001', $asAdmin['student']['student']['student_number']);
        $this->assertSame('F-260001', $asAdmin['student']['student']['archive_records']['folder_code']);
        $this->assertSame(1.38, $asAdmin['summary']['summary']['overall_gwa']);
        $this->assertArrayHasKey('latin_honors', $asAdmin['summary']['summary']);
        $this->assertSame(['GEC4', 'TPC1', 'TPC2'], array_column($asAdmin['summary']['curriculum']['roadmap'], 'subject_code'));
        $this->assertSame(['form137.pdf'], array_column($asAdmin['documents'], 'original_name'));
        $this->assertArrayNotHasKey('file_path', $asAdmin['documents'][0]);
    }

    public function test_both_can_download_the_transcript_and_documents(): void
    {
        $id = $this->student->student_id;

        foreach ([$this->staff, $this->admin] as $user) {
            Sanctum::actingAs($user, ['*']);
            $transcript = $this->get("/api/staff/students/{$id}/transcript")->assertOk();
            $this->assertStringStartsWith('%PDF-', $transcript->streamedContent());
            $this->get("/api/staff/students/{$id}/documents/{$this->document->id}/download")->assertOk()->assertDownload('form137.pdf');
        }
    }

    public function test_admin_cannot_edit_or_archive_from_the_record_page(): void
    {
        $id = $this->student->student_id;
        Sanctum::actingAs($this->admin, ['*']);

        $this->putJson("/api/staff/students/{$id}", [
            'student_number' => '260001', 'first_name' => 'Changed', 'last_name' => 'Name', 'date_of_birth' => '2005-01-01',
            'email' => 'juan@tmcc.test', 'enrollment_date' => '2026-06-01',
        ])->assertForbidden();
        $this->postJson("/api/staff/students/{$id}/archive", [
            'record_type' => 'Form 137', 'cabinet_no' => 'C9', 'shelf_no' => 'S9', 'folder_code' => 'X', 'document_status' => 'Complete',
        ])->assertForbidden();

        $this->assertSame('Juan', $this->student->fresh()->first_name);
        $this->assertSame(1, ArchiveRecord::where('student_id', $id)->count());
    }

    public function test_students_are_refused(): void
    {
        $id = $this->student->student_id;
        Sanctum::actingAs($this->student->user, ['*']);

        $this->getJson("/api/staff/students/{$id}")->assertForbidden();
        $this->getJson("/api/staff/students/{$id}/academic-summary")->assertForbidden();
        $this->getJson("/api/staff/students/{$id}/documents")->assertForbidden();
        $this->get("/api/staff/students/{$id}/transcript")->assertForbidden();
        $this->get("/api/staff/students/{$id}/documents/{$this->document->id}/download")->assertForbidden();
    }
}
