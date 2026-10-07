<?php

namespace Tests\Feature;

use App\Models\ArchiveRecord;
use App\Models\Student;
use App\Models\SystemLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #97: the registrar can change where a student's paper records are kept
 * (set at New Student, previously never editable). Admins read only;
 * students are refused.
 */
class ArchiveLocationTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private const ORIGINAL = [
        'record_type'     => 'Form 137',
        'cabinet_no'      => 'C1',
        'shelf_no'        => 'S1',
        'folder_code'     => 'F-0001',
        'document_status' => 'pending',
    ];

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->student = $this->makeStudent($this->makeProgram());
        ArchiveRecord::create(['student_id' => $this->student->student_id] + self::ORIGINAL);
        Sanctum::actingAs($this->makeUser('staff'), ['*']);
    }

    private function update(array $body)
    {
        return $this->putJson("/api/staff/students/{$this->student->student_id}/archive-location", $body);
    }

    public function test_the_registrar_updates_the_location_and_the_change_is_logged(): void
    {
        $this->update(['cabinet_no' => 'C2', 'shelf_no' => 'S4', 'document_status' => 'stored'] + self::ORIGINAL)
            ->assertOk()
            ->assertJsonPath('message', 'Archive location updated.')
            ->assertJsonPath('archive_record.cabinet_no', 'C2');

        $this->assertDatabaseHas('archive_records', [
            'student_id' => $this->student->student_id, 'cabinet_no' => 'C2', 'shelf_no' => 'S4',
            'folder_code' => 'F-0001', 'document_status' => 'stored',
        ]);
        $this->assertSame(1, ArchiveRecord::count(), 'The existing row is updated, not duplicated.');

        $this->assertSame(
            'Archive location changed for student 2026-0001: cabinet number C1 → C2; shelf number S1 → S4; document status pending → stored',
            SystemLog::sole()->action,
        );
    }

    public function test_the_record_page_shows_the_new_location(): void
    {
        $this->update(['folder_code' => 'F-0777'] + self::ORIGINAL)->assertOk();

        $this->getJson("/api/staff/students/{$this->student->student_id}")
            ->assertOk()
            ->assertJsonPath('student.archive_records.folder_code', 'F-0777');
    }

    public function test_saving_the_same_values_changes_and_logs_nothing(): void
    {
        $this->update(self::ORIGINAL)->assertOk()->assertJsonPath('message', 'Nothing changed.');

        $this->assertSame(0, SystemLog::count());
    }

    public function test_a_student_without_an_archive_row_gets_one(): void
    {
        ArchiveRecord::query()->delete();

        $this->update(self::ORIGINAL)->assertOk();

        $this->assertDatabaseHas('archive_records', ['student_id' => $this->student->student_id] + self::ORIGINAL);
        $this->assertStringContainsString('cabinet number (none) → C1', SystemLog::sole()->action);
    }

    public function test_the_fields_are_validated_like_archive_record(): void
    {
        $this->update([])
            ->assertStatus(422)
            ->assertJsonValidationErrors(array_keys(self::ORIGINAL))
            ->assertJsonPath('errors.folder_code.0', 'The folder code field is required.');

        $this->update(['shelf_no' => str_repeat('S', 51)] + self::ORIGINAL)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['shelf_no']);

        $this->assertDatabaseHas('archive_records', self::ORIGINAL);
        $this->assertSame(0, SystemLog::count());
    }

    public function test_an_unknown_student_is_404(): void
    {
        $this->putJson('/api/staff/students/999999/archive-location', self::ORIGINAL)->assertNotFound();
    }

    public function test_admins_and_students_cannot_edit_the_location(): void
    {
        Sanctum::actingAs($this->makeUser('admin'), ['*']);
        $this->update(['cabinet_no' => 'C9'] + self::ORIGINAL)->assertForbidden();

        Sanctum::actingAs($this->makeUser('student', '2026-0001'), ['*']);
        $this->update(['cabinet_no' => 'C9'] + self::ORIGINAL)->assertForbidden();

        $this->assertDatabaseHas('archive_records', self::ORIGINAL);
        $this->assertSame(0, SystemLog::count());
    }
}
