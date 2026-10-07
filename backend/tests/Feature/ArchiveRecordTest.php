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
 * #85: Archive Record refused missing fields with a 500 from the NOT NULL
 * constraints; it now answers 422 naming each field and saves nothing.
 */
class ArchiveRecordTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private const VALID = [
        'record_type'     => 'Form 137',
        'cabinet_no'      => 'C2',
        'shelf_no'        => 'S4',
        'folder_code'     => 'F-0099',
        'document_status' => 'stored',
    ];

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->student = $this->makeStudent($this->makeProgram());
        Sanctum::actingAs($this->makeUser('staff'), ['*']);
    }

    private function archive(array $body)
    {
        return $this->postJson("/api/staff/students/{$this->student->student_id}/archive", $body);
    }

    public function test_an_empty_body_is_refused_with_every_field_named(): void
    {
        $this->archive([])
            ->assertStatus(422)
            ->assertJsonValidationErrors(array_keys(self::VALID))
            ->assertJsonPath('errors.cabinet_no.0', 'The cabinet number field is required.')
            ->assertJsonPath('errors.document_status.0', 'The document status field is required.');

        $this->assertSame(0, ArchiveRecord::count());
        $this->assertSame(0, SystemLog::where('action', 'Student archived')->count());
    }

    public function test_one_missing_field_is_named_alone(): void
    {
        $body = self::VALID;
        unset($body['shelf_no']);

        $this->archive($body)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['shelf_no'])
            ->assertJsonMissingValidationErrors(['record_type', 'cabinet_no', 'folder_code', 'document_status']);

        $this->assertSame(0, ArchiveRecord::count());
    }

    public function test_over_long_values_are_refused(): void
    {
        $this->archive(['cabinet_no' => str_repeat('C', 51)] + self::VALID)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cabinet_no']);
    }

    public function test_a_valid_body_archives_as_before(): void
    {
        $this->archive(self::VALID)->assertOk()->assertJsonPath('message', 'Student archived successfully.');

        $this->assertDatabaseHas('archive_records', ['student_id' => $this->student->student_id] + self::VALID);
        $this->assertSame(1, SystemLog::where('action', 'Student archived')->count());
    }

    public function test_only_the_registrar_may_archive(): void
    {
        Sanctum::actingAs($this->makeUser('admin'), ['*']);
        $this->archive(self::VALID)->assertForbidden();

        Sanctum::actingAs($this->makeUser('student', '2026-0001'), ['*']);
        $this->archive([])->assertForbidden();

        $this->assertSame(0, ArchiveRecord::count());
    }
}
