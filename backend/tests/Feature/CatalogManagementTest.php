<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\EnrollmentAuditLog;
use App\Models\Grade;
use App\Models\Program;
use App\Models\ProgramChangeLog;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * C2: registrar staff maintain the subject and program catalogue. Admins can
 * read it. Nothing still in use can be deleted (#15): the API answers 409 and
 * says what uses it, and the foreign keys restrict the delete even if the API
 * is bypassed. Such rows are archived instead.
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
            ->assertStatus(409)
            ->assertJsonPath('message', 'B cannot be deleted: used by 1 curriculum entry; archive it instead.');

        $this->assertModelExists($this->subjects['B']);
        $this->assertModelExists($this->curricula['B']);
    }

    public function test_a_prerequisite_subject_cannot_be_deleted(): void
    {
        // Take A out of the curriculum so only its prerequisite role remains.
        $this->curricula['A']->delete();

        $this->deleteJson("/api/staff/subjects/{$this->subjects['A']->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'A cannot be deleted: used by 1 prerequisite link; archive it instead.');

        $this->assertModelExists($this->subjects['A']);
        $this->assertSame([$this->subjects['A']->id], $this->curricula['B']->prerequisites()->pluck('subjects.id')->all());
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
            ->assertStatus(409)
            ->assertJsonPath('message', 'FREE cannot be deleted: used by 1 grade; archive it instead.');

        $this->assertModelExists($subject);
        $this->assertSame(1, Grade::where('subject_id', $subject->id)->count());
    }

    public function test_the_refusal_names_everything_that_uses_the_subject(): void
    {
        $student = $this->makeStudent($this->program);
        $this->recordGrade($student, 'A', '2026-2027', 1, 2.00, 'Passed');
        $enrollment = Enrollment::create([
            'student_id' => $student->student_id, 'subject_id' => $this->subjects['A']->id,
            'academic_year' => '2026-2027', 'semester' => '1', 'status' => 'completed',
        ]);
        EnrollmentAuditLog::create([
            'student_id' => $student->student_id, 'enrollment_id' => $enrollment->id, 'subject_id' => $this->subjects['A']->id,
            'academic_year' => '2026-2027', 'semester' => '1', 'action' => 'created',
        ]);
        // A soft-deleted enrollment is still in the database and still counts.
        $enrollment->delete();

        $this->deleteJson("/api/staff/subjects/{$this->subjects['A']->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'A cannot be deleted: used by 1 curriculum entry, 1 prerequisite link, 1 enrollment, 1 grade and 1 audit log entry; archive it instead.')
            ->assertJsonPath('usage.grade', 1);

        $this->assertSame(1, Grade::count());
        $this->assertSame(1, Enrollment::withTrashed()->count());
        $this->assertSame(1, EnrollmentAuditLog::count());
    }

    public function test_the_database_refuses_to_delete_a_subject_in_use(): void
    {
        $student = $this->makeStudent($this->program);
        $this->recordGrade($student, 'B', '2026-2027', 2, 2.00, 'Passed');
        Enrollment::create([
            'student_id' => $student->student_id, 'subject_id' => $this->subjects['B']->id,
            'academic_year' => '2026-2027', 'semester' => '2', 'status' => 'completed',
        ]);

        // Bypassing the controller must not cascade into student records.
        try {
            $this->subjects['B']->delete();
            $this->fail('Deleting a subject in use should violate a foreign key.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('foreign key', $e->getMessage());
        }

        $this->assertModelExists($this->subjects['B']);
        $this->assertSame(1, Grade::where('subject_id', $this->subjects['B']->id)->count());
        $this->assertSame(1, Enrollment::where('subject_id', $this->subjects['B']->id)->count());
        $this->assertModelExists($this->curricula['B']);
    }

    public function test_the_database_refuses_to_delete_a_prerequisite_subject(): void
    {
        $this->curricula['A']->delete();

        $this->expectException(QueryException::class);
        $this->subjects['A']->delete();
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
            ->assertStatus(409)
            ->assertJsonPath('message', 'BSIT cannot be deleted: used by 2 curriculum entries; archive it instead.');

        $this->assertModelExists($this->program);
        $this->assertSame(2, $this->program->curriculum()->count());
    }

    public function test_a_program_with_students_cannot_be_deleted(): void
    {
        $empty = Program::create(['code' => 'BSX', 'name' => 'Empty Program']);
        $student = $this->makeStudent($empty);

        $this->deleteJson("/api/staff/programs/{$empty->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'BSX cannot be deleted: used by 1 student; archive it instead.');

        $this->assertModelExists($empty);
        $this->assertSame($empty->id, $student->fresh()->program_id);
    }

    public function test_a_program_in_student_history_cannot_be_deleted(): void
    {
        $old = Program::create(['code' => 'BSX', 'name' => 'Former Program']);
        $student = $this->makeStudent($this->program);
        ProgramChangeLog::create([
            'student_id' => $student->student_id, 'old_program_id' => $old->id, 'new_program_id' => $this->program->id,
            'reason' => 'Shifted', 'changed_by' => $this->staff->id,
        ]);

        $this->deleteJson("/api/staff/programs/{$old->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'BSX cannot be deleted: used by 1 program change log entry; archive it instead.');

        $this->assertDatabaseHas('program_change_logs', ['old_program_id' => $old->id]);
    }

    public function test_the_database_refuses_to_delete_a_program_in_use(): void
    {
        $student = $this->makeStudent($this->program);

        // Neither the curriculum nor the student may be cascaded or detached.
        try {
            $this->program->delete();
            $this->fail('Deleting a program in use should violate a foreign key.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('foreign key', $e->getMessage());
        }

        $this->assertModelExists($this->program);
        $this->assertSame(2, $this->program->curriculum()->count());
        $this->assertSame($this->program->id, $student->fresh()->program_id);
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

    // ------------------------------------------------------------- archiving

    public function test_registrar_archives_and_unarchives_a_subject_in_use(): void
    {
        $id = $this->subjects['A']->id;

        $this->patchJson("/api/staff/subjects/{$id}/archive")
            ->assertOk()
            ->assertJsonPath('message', 'Subject archived.');
        $this->assertNotNull($this->subjects['A']->fresh()->archived_at);
        $this->assertDatabaseHas('system_logs', ['action' => 'Subject archived: A — Subject A', 'user_id' => $this->staff->id]);

        $subjects = collect($this->getJson('/api/staff/subjects')->json('subjects'))->keyBy('code');
        $this->assertTrue($subjects['A']['archived']);
        $this->assertTrue($subjects['A']['in_use']);
        $this->assertFalse($subjects['B']['archived']);

        $this->patchJson("/api/staff/subjects/{$id}/unarchive")->assertOk()->assertJsonPath('message', 'Subject unarchived.');
        $this->assertNull($this->subjects['A']->fresh()->archived_at);

        // Archiving changes nothing else: the curriculum still lists it.
        $this->assertModelExists($this->curricula['A']);
    }

    public function test_registrar_archives_and_unarchives_a_program(): void
    {
        $this->patchJson("/api/staff/programs/{$this->program->id}/archive")
            ->assertOk()
            ->assertJsonPath('message', 'Program archived.');
        $this->assertDatabaseHas('system_logs', ['action' => 'Program archived: BSIT — BS Information Technology']);
        $this->assertTrue($this->getJson('/api/staff/programs')->json('programs.0.archived'));

        $this->patchJson("/api/staff/programs/{$this->program->id}/unarchive")->assertOk();
        $this->assertFalse($this->getJson('/api/staff/programs')->json('programs.0.archived'));
    }

    public function test_archiving_cannot_be_set_through_the_edit_form(): void
    {
        $subject = $this->looseSubject();

        $this->putJson("/api/staff/subjects/{$subject->id}", ['code' => 'FREE', 'title' => 'Unused Subject', 'units' => 3, 'archived_at' => '2026-01-01'])
            ->assertOk();

        $this->assertNull($subject->fresh()->archived_at);
    }

    public function test_archiving_a_missing_record_returns_404(): void
    {
        $this->patchJson('/api/staff/subjects/999999/archive')->assertNotFound();
        $this->patchJson('/api/staff/programs/999999/unarchive')->assertNotFound();
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
        $this->patchJson("/api/staff/subjects/{$subject->id}/archive")->assertForbidden();
        $this->patchJson("/api/staff/programs/{$this->program->id}/archive")->assertForbidden();

        $this->assertModelExists($subject);
        $this->assertNull($subject->fresh()->archived_at);
    }

    public function test_students_cannot_see_the_catalogue(): void
    {
        Sanctum::actingAs($this->makeUser('student', '2026-0001'), ['*']);

        $this->getJson('/api/staff/subjects')->assertForbidden();
        $this->postJson('/api/staff/programs', ['code' => 'BSX', 'name' => 'X'])->assertForbidden();
        $this->patchJson("/api/staff/subjects/{$this->subjects['A']->id}/archive")->assertForbidden();
    }

    public function test_missing_records_return_404(): void
    {
        $this->putJson('/api/staff/subjects/999999', $this->subjectPayload())->assertNotFound();
        $this->deleteJson('/api/staff/programs/999999')->assertNotFound();
    }
}
