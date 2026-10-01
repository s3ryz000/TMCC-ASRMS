<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\Program;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * C2: registrar staff maintain the subject and program catalogue. Admins can
 * read it; nobody can delete something still in use, because the foreign keys
 * cascade and would take grades and curricula with it.
 */
class CatalogManagementTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private User $staff;
    private User $admin;
    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->staff = $this->makeUser('staff');
        $this->admin = $this->makeUser('admin');
        $this->program = $this->makeProgram();
        $this->makeCurriculum($this->program, ['A' => [1, 1], 'B' => [1, 2]], ['B' => ['A']]);

        Sanctum::actingAs($this->staff, ['*']);
    }

    private function subjectPayload(array $overrides = []): array
    {
        return array_merge(['code' => 'IT 101', 'title' => 'Intro to Computing', 'units' => 3, 'description' => null], $overrides);
    }

    private function looseSubject(): Subject
    {
        return Subject::create(['code' => 'FREE', 'title' => 'Unused Subject', 'units' => 3]);
    }

    // ------------------------------------------------------------- subjects

    public function test_registrar_creates_a_subject(): void
    {
        $this->postJson('/api/staff/subjects', $this->subjectPayload())
            ->assertCreated()
            ->assertJsonPath('subject.code', 'IT 101')
            ->assertJsonPath('subject.units', 3);

        $this->assertDatabaseHas('subjects', ['code' => 'IT 101', 'title' => 'Intro to Computing']);
        $this->assertDatabaseHas('system_logs', ['action' => 'Subject created: IT 101 — Intro to Computing', 'user_id' => $this->staff->id]);
    }

    public function test_subject_code_must_be_unique(): void
    {
        $this->postJson('/api/staff/subjects', $this->subjectPayload())->assertCreated();

        $this->postJson('/api/staff/subjects', $this->subjectPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code' => 'A subject with this code already exists.']);

        // One code per course (#16): a different title no longer makes a second
        // subject under the same code, whatever its case or spacing.
        $this->postJson('/api/staff/subjects', $this->subjectPayload(['title' => 'Intro to Computing (BSE)']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code' => 'A subject with this code already exists.']);
        $this->postJson('/api/staff/subjects', $this->subjectPayload(['code' => ' it   101 ', 'title' => 'Something Else']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code' => 'A subject with this code already exists.']);

        $this->assertSame(1, Subject::where('code', 'IT 101')->count());
    }

    public function test_subject_code_is_stored_trimmed_and_uppercase(): void
    {
        $this->postJson('/api/staff/subjects', $this->subjectPayload(['code' => '  gec-pc ', 'title' => 'Purposive Communication']))
            ->assertCreated()
            ->assertJsonPath('subject.code', 'GEC-PC');

        $this->postJson('/api/staff/subjects', $this->subjectPayload(['code' => 'it   101']))
            ->assertCreated()
            ->assertJsonPath('subject.code', 'IT 101');
    }

    /**
     * @dataProvider nearDuplicateTitles
     */
    public function test_a_near_duplicate_title_is_rejected(string $title): void
    {
        Subject::create(['code' => 'HMPE 2', 'title' => 'Bar and Beverage Management with Laboratory', 'units' => 3]);

        $this->postJson('/api/staff/subjects', $this->subjectPayload(['code' => 'NEW 1', 'title' => $title]))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'title' => 'This looks like HMPE 2 Bar and Beverage Management with Laboratory, which already exists. Reuse it instead.',
            ]);

        $this->assertDatabaseMissing('subjects', ['code' => 'NEW 1']);
    }

    public static function nearDuplicateTitles(): array
    {
        return [
            'same title'         => ['Bar and Beverage Management with Laboratory'],
            'case and spacing'   => ['  bar AND beverage   management with laboratory '],
            'lab abbreviation'   => ['Bar and Beverage Management with Lab'],
            'ampersand'          => ['Bar & Beverage Management with Lab'],
            'leading the'        => ['The Bar and Beverage Management with Laboratory'],
        ];
    }

    public function test_the_near_duplicate_message_names_the_existing_subject(): void
    {
        Subject::create(['code' => 'GEC-PC', 'title' => 'Purposive Communication', 'units' => 3]);

        $this->postJson('/api/staff/subjects', $this->subjectPayload(['code' => 'GE 5', 'title' => 'purposive communication']))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'title' => 'This looks like GEC-PC Purposive Communication, which already exists. Reuse it instead.',
            ]);
    }

    public function test_a_subject_can_keep_its_own_title_when_updated(): void
    {
        $subject = $this->looseSubject();

        // Same title, re-cased and re-spaced, on the same subject: allowed.
        $this->putJson("/api/staff/subjects/{$subject->id}", ['code' => 'free', 'title' => '  unused   subject ', 'units' => 3])
            ->assertOk()
            ->assertJsonPath('subject.code', 'FREE');

        // Taking another subject's title is not.
        $this->putJson("/api/staff/subjects/{$subject->id}", ['code' => 'FREE', 'title' => 'Subject A', 'units' => 3])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');

        // Nor is taking another subject's code.
        $this->putJson("/api/staff/subjects/{$subject->id}", ['code' => 'a', 'title' => 'Unused Subject', 'units' => 3])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code' => 'A subject with this code already exists.']);
    }

    public function test_resubmitting_a_mixed_case_code_keeps_its_spelling(): void
    {
        $subject = Subject::create(['code' => 'PATHFit 1', 'title' => 'Movement Competency Training', 'units' => 2]);

        $this->putJson("/api/staff/subjects/{$subject->id}", ['code' => 'PATHFit 1', 'title' => 'Movement Competency Training', 'units' => 2, 'description' => 'PE'])
            ->assertOk();

        $this->assertSame('PATHFit 1', $subject->fresh()->code);
    }

    /**
     * @dataProvider invalidSubjects
     */
    public function test_invalid_subjects_are_rejected(array $overrides, string $field): void
    {
        $this->postJson('/api/staff/subjects', $this->subjectPayload($overrides))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }

    public static function invalidSubjects(): array
    {
        return [
            'missing code'      => [['code' => ''], 'code'],
            'code too long'     => [['code' => str_repeat('X', 21)], 'code'],
            'missing title'     => [['title' => ''], 'title'],
            'title too long'    => [['title' => str_repeat('x', 151)], 'title'],
            'missing units'     => [['units' => null], 'units'],
            'fractional units'  => [['units' => 1.5], 'units'],
            'negative units'    => [['units' => -1], 'units'],
            'units above 12'    => [['units' => 13], 'units'],
            'long description'  => [['description' => str_repeat('x', 256)], 'description'],
        ];
    }

    public function test_registrar_edits_a_subject(): void
    {
        $subject = $this->looseSubject();

        $this->putJson("/api/staff/subjects/{$subject->id}", ['code' => 'FREE 2', 'title' => 'Renamed', 'units' => 2])
            ->assertOk()
            ->assertJsonPath('subject.title', 'Renamed');

        $this->assertSame(2, $subject->fresh()->units);
    }

    public function test_editing_keeps_its_own_code_and_title(): void
    {
        $subject = $this->looseSubject();

        $this->putJson("/api/staff/subjects/{$subject->id}", ['code' => 'FREE', 'title' => 'Unused Subject', 'units' => 3, 'description' => 'Now described'])
            ->assertOk();
    }

    public function test_units_are_locked_once_grades_exist(): void
    {
        $student = $this->makeStudent($this->program);
        $this->recordGrade($student, 'A', '2026-2027', 1, 2.00, 'Passed');

        $this->putJson("/api/staff/subjects/{$this->subjects['A']->id}", ['code' => 'A', 'title' => 'Subject A', 'units' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('units');

        // The title can still be corrected.
        $this->putJson("/api/staff/subjects/{$this->subjects['A']->id}", ['code' => 'A', 'title' => 'Subject A (revised)', 'units' => 3])
            ->assertOk();
    }

    public function test_registrar_deletes_an_unused_subject(): void
    {
        $subject = $this->looseSubject();

        $this->deleteJson("/api/staff/subjects/{$subject->id}")->assertOk();

        $this->assertModelMissing($subject);
    }

    public function test_a_subject_in_a_curriculum_cannot_be_deleted(): void
    {
        $this->deleteJson("/api/staff/subjects/{$this->subjects['B']->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'B cannot be deleted: it is part of a program curriculum.');
    }

    public function test_a_prerequisite_subject_cannot_be_deleted(): void
    {
        // Take A out of the curriculum so only its prerequisite role remains.
        $this->curricula['A']->delete();

        $this->deleteJson("/api/staff/subjects/{$this->subjects['A']->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'A cannot be deleted: it is a prerequisite of another subject.');
    }

    public function test_deleting_a_graded_subject_is_refused_and_grades_survive(): void
    {
        $subject = $this->looseSubject();
        $student = $this->makeStudent($this->program);
        Grade::create([
            'student_id' => $student->student_id, 'subject_id' => $subject->id,
            'academic_year' => '2026-2027', 'semester' => '1', 'grade_value' => 2.00, 'status' => 'Passed',
        ]);

        $this->deleteJson("/api/staff/subjects/{$subject->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'FREE cannot be deleted: grades have been recorded for it.');

        $this->assertSame(1, Grade::where('subject_id', $subject->id)->count());
    }

    public function test_subject_list_reports_usage(): void
    {
        $this->looseSubject();

        $subjects = collect($this->getJson('/api/staff/subjects')->assertOk()->json('subjects'))->keyBy('code');

        $this->assertTrue($subjects['A']['in_use']);
        $this->assertSame(1, $subjects['A']['curriculum_count']);
        $this->assertFalse($subjects['FREE']['in_use']);
    }

    // ------------------------------------------------------------- programs

    public function test_registrar_creates_and_edits_a_program(): void
    {
        $id = $this->postJson('/api/staff/programs', ['code' => 'BSCS', 'name' => 'BS Computer Science'])
            ->assertCreated()
            ->json('program.id');

        $this->putJson("/api/staff/programs/{$id}", ['code' => 'BSCS', 'name' => 'Bachelor of Science in Computer Science'])
            ->assertOk()
            ->assertJsonPath('program.name', 'Bachelor of Science in Computer Science');

        $this->assertDatabaseHas('system_logs', ['action' => 'Program created: BSCS — BS Computer Science']);
    }

    public function test_program_code_must_be_unique(): void
    {
        $this->postJson('/api/staff/programs', ['code' => 'BSIT', 'name' => 'Duplicate'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code' => 'A program with this code already exists.']);
    }

    public function test_program_needs_a_code_and_name(): void
    {
        $this->postJson('/api/staff/programs', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code', 'name']);
    }

    public function test_a_program_with_a_curriculum_cannot_be_deleted(): void
    {
        $this->deleteJson("/api/staff/programs/{$this->program->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'BSIT cannot be deleted: it has a curriculum.');

        $this->assertSame(2, $this->program->curriculum()->count());
    }

    public function test_a_program_with_students_cannot_be_deleted(): void
    {
        $empty = Program::create(['code' => 'BSX', 'name' => 'Empty Program']);
        $this->makeStudent($empty);

        $this->deleteJson("/api/staff/programs/{$empty->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'BSX cannot be deleted: students are enrolled in it.');
    }

    public function test_an_unused_program_can_be_deleted(): void
    {
        $empty = Program::create(['code' => 'BSX', 'name' => 'Empty Program']);

        $this->deleteJson("/api/staff/programs/{$empty->id}")->assertOk();

        $this->assertModelMissing($empty);
    }

    public function test_program_list_keeps_the_fields_other_pages_use(): void
    {
        $this->getJson('/api/staff/programs')
            ->assertOk()
            ->assertJsonPath('programs.0.code', 'BSIT')
            ->assertJsonPath('programs.0.name', 'BS Information Technology')
            ->assertJsonPath('programs.0.curriculum_count', 2)
            ->assertJsonPath('programs.0.in_use', true);
    }

    // ---------------------------------------------------------------- access

    public function test_admin_can_read_but_not_change_the_catalogue(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $subject = $this->looseSubject();

        $this->getJson('/api/staff/subjects')->assertOk();
        $this->getJson('/api/staff/programs')->assertOk();

        $this->postJson('/api/staff/subjects', $this->subjectPayload())->assertForbidden();
        $this->putJson("/api/staff/subjects/{$subject->id}", $this->subjectPayload())->assertForbidden();
        $this->deleteJson("/api/staff/subjects/{$subject->id}")->assertForbidden();
        $this->postJson('/api/staff/programs', ['code' => 'BSX', 'name' => 'X'])->assertForbidden();
        $this->putJson("/api/staff/programs/{$this->program->id}", ['code' => 'BSIT', 'name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/staff/programs/{$this->program->id}")->assertForbidden();

        $this->assertModelExists($subject);
    }

    public function test_students_cannot_see_the_catalogue(): void
    {
        Sanctum::actingAs($this->makeUser('student', '2026-0001'), ['*']);

        $this->getJson('/api/staff/subjects')->assertForbidden();
        $this->postJson('/api/staff/programs', ['code' => 'BSX', 'name' => 'X'])->assertForbidden();
    }

    public function test_missing_records_return_404(): void
    {
        $this->putJson('/api/staff/subjects/999999', $this->subjectPayload())->assertNotFound();
        $this->deleteJson('/api/staff/programs/999999')->assertNotFound();
    }
}
