<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\SystemLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * §3.9.2: registrar staff can "upload digital forms or files associated with
 * student records". Uploading and deleting are registrar-only record-keeping;
 * admins keep read access, as they do for the rest of the student record.
 */
class StaffDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;
    private User $student;
    private Student $record;
    private Student $otherRecord;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        foreach (['staff', 'admin', 'student'] as $role) {
            Role::findOrCreate($role, 'api');
        }

        $this->admin = $this->makeUser('admin');
        $this->staff = $this->makeUser('staff');
        $this->student = $this->makeUser('student');

        $program = Program::create(['code' => 'BSIT', 'name' => 'BS Information Technology']);

        $this->record = $this->makeStudent($program, '2026-0042', 'maria@tmcc.test');
        $this->otherRecord = $this->makeStudent($program, '2026-0043', 'juan@tmcc.test');
    }

    private function makeUser(string $role): User
    {
        $user = User::create([
            'name'     => ucfirst($role) . ' User',
            'email'    => $role . '@tmcc.test',
            'username' => $role . '01',
            'role'     => $role,
            'password' => bcrypt('secret'),
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function makeStudent(Program $program, string $number, string $email): Student
    {
        return Student::create([
            'program_id'      => $program->id,
            'student_number'  => $number,
            'first_name'      => 'Maria',
            'last_name'       => 'Santos',
            'date_of_birth'   => '2005-05-05',
            'email'           => $email,
            'sex'             => 'F',
            'enrollment_date' => '2026-06-01',
        ]);
    }

    private function documentsUrl(?Student $student = null, string $suffix = ''): string
    {
        return '/api/staff/students/' . ($student ?? $this->record)->student_id . '/documents' . $suffix;
    }

    private function upload(array $overrides = [])
    {
        return $this->post($this->documentsUrl(), array_merge([
            'document'      => UploadedFile::fake()->create('form-137.pdf', 200, 'application/pdf'),
            'document_type' => 'Form 137',
            'description'   => 'Permanent record from previous school',
        ], $overrides), ['Accept' => 'application/json']);
    }

    private function seedDocument(?Student $student = null): StudentDocument
    {
        $student ??= $this->record;
        $path = "student-documents/{$student->student_id}/stored.pdf";
        Storage::disk('local')->put($path, 'pdf-bytes');

        return StudentDocument::create([
            'student_id'    => $student->student_id,
            'uploaded_by'   => $this->staff->id,
            'document_type' => 'Birth Certificate',
            'file_path'     => $path,
            'original_name' => 'psa-birth-certificate.pdf',
            'mime'          => 'application/pdf',
            'size'          => 9,
        ]);
    }

    // ------------------------------------------------------------- uploading

    public function test_registrar_can_upload_a_document_to_a_student_record(): void
    {
        Sanctum::actingAs($this->staff, ['*']);

        $response = $this->upload()
            ->assertCreated()
            ->assertJsonPath('document.document_type', 'Form 137')
            ->assertJsonPath('document.original_name', 'form-137.pdf')
            ->assertJsonPath('document.uploader.name', 'Staff User')
            ->assertJsonMissingPath('document.file_path');

        $document = StudentDocument::findOrFail($response->json('document.id'));

        $this->assertSame($this->record->student_id, $document->student_id);
        $this->assertSame($this->staff->id, $document->uploaded_by);
        $this->assertStringStartsWith("student-documents/{$this->record->student_id}/", $document->file_path);
        Storage::disk('local')->assertExists($document->file_path);
    }

    public function test_upload_is_written_to_the_system_log(): void
    {
        Sanctum::actingAs($this->staff, ['*']);

        $this->upload()->assertCreated();

        $log = SystemLog::latest('log_id')->first();
        $this->assertSame($this->staff->id, $log->user_id);
        $this->assertStringContainsString('Uploaded Form 137 for 2026-0042', $log->action);
    }

    public function test_admin_cannot_upload_documents(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->upload()->assertForbidden();

        $this->assertSame(0, StudentDocument::count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_student_cannot_upload_documents(): void
    {
        Sanctum::actingAs($this->student, ['*']);

        $this->upload()->assertForbidden();

        $this->assertSame(0, StudentDocument::count());
    }

    public function test_unauthenticated_upload_is_rejected(): void
    {
        $this->upload()->assertUnauthorized();
    }

    public function test_upload_to_unknown_student_returns_404(): void
    {
        Sanctum::actingAs($this->staff, ['*']);

        $this->post('/api/staff/students/999999/documents', [
            'document'      => UploadedFile::fake()->create('form-137.pdf', 10, 'application/pdf'),
            'document_type' => 'Form 137',
        ], ['Accept' => 'application/json'])->assertNotFound();

        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    /**
     * @dataProvider invalidUploads
     */
    public function test_invalid_uploads_are_rejected(callable $overrides, string $errorField): void
    {
        Sanctum::actingAs($this->staff, ['*']);

        $this->upload($overrides())
            ->assertStatus(422)
            ->assertJsonValidationErrors($errorField);

        $this->assertSame(0, StudentDocument::count());
    }

    public static function invalidUploads(): array
    {
        return [
            'missing file'        => [fn () => ['document' => null], 'document'],
            'executable script'   => [fn () => ['document' => UploadedFile::fake()->create('shell.php', 1, 'application/x-php')], 'document'],
            'over 5 MB'           => [fn () => ['document' => UploadedFile::fake()->create('scan.pdf', 5121, 'application/pdf')], 'document'],
            'missing type'        => [fn () => ['document_type' => ''], 'document_type'],
            'type too long'       => [fn () => ['document_type' => str_repeat('x', 101)], 'document_type'],
        ];
    }

    // ------------------------------------------------------ listing & download

    public function test_staff_and_admin_can_list_a_students_documents(): void
    {
        $this->seedDocument();
        $this->seedDocument($this->otherRecord);

        foreach ([$this->staff, $this->admin] as $user) {
            Sanctum::actingAs($user, ['*']);

            $this->getJson($this->documentsUrl())
                ->assertOk()
                ->assertJsonCount(1)
                ->assertJsonPath('0.document_type', 'Birth Certificate')
                ->assertJsonMissingPath('0.file_path');
        }
    }

    public function test_student_cannot_list_documents(): void
    {
        Sanctum::actingAs($this->student, ['*']);

        $this->getJson($this->documentsUrl())->assertForbidden();
    }

    public function test_staff_and_admin_can_download_a_document(): void
    {
        $document = $this->seedDocument();

        foreach ([$this->staff, $this->admin] as $user) {
            Sanctum::actingAs($user, ['*']);

            $response = $this->get($this->documentsUrl(null, "/{$document->id}/download"));

            $response->assertOk()->assertDownload('psa-birth-certificate.pdf');
            $this->assertSame('pdf-bytes', $response->streamedContent());
        }
    }

    public function test_document_cannot_be_downloaded_through_another_students_record(): void
    {
        $document = $this->seedDocument($this->otherRecord);
        Sanctum::actingAs($this->staff, ['*']);

        $this->getJson($this->documentsUrl($this->record, "/{$document->id}/download"))
            ->assertNotFound();
    }

    public function test_download_reports_missing_file(): void
    {
        $document = $this->seedDocument();
        Storage::disk('local')->delete($document->file_path);
        Sanctum::actingAs($this->staff, ['*']);

        $this->getJson($this->documentsUrl(null, "/{$document->id}/download"))
            ->assertNotFound()
            ->assertJsonPath('message', 'Document file does not exist on server.');
    }

    // --------------------------------------------------------------- deleting

    public function test_registrar_can_delete_a_document(): void
    {
        $document = $this->seedDocument();
        Sanctum::actingAs($this->staff, ['*']);

        $this->deleteJson($this->documentsUrl(null, "/{$document->id}"))->assertOk();

        $this->assertModelMissing($document);
        Storage::disk('local')->assertMissing($document->file_path);
        $this->assertStringContainsString(
            'Deleted Birth Certificate for 2026-0042',
            SystemLog::latest('log_id')->first()->action
        );
    }

    public function test_admin_cannot_delete_a_document(): void
    {
        $document = $this->seedDocument();
        Sanctum::actingAs($this->admin, ['*']);

        $this->deleteJson($this->documentsUrl(null, "/{$document->id}"))->assertForbidden();

        $this->assertModelExists($document);
        Storage::disk('local')->assertExists($document->file_path);
    }

    public function test_document_cannot_be_deleted_through_another_students_record(): void
    {
        $document = $this->seedDocument($this->otherRecord);
        Sanctum::actingAs($this->staff, ['*']);

        $this->deleteJson($this->documentsUrl($this->record, "/{$document->id}"))->assertNotFound();

        $this->assertModelExists($document);
    }
}
