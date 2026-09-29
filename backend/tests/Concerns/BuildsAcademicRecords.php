<?php

namespace Tests\Concerns;

use App\Models\Curriculum;
use App\Models\Grade;
use App\Models\Program;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

/**
 * Shared fixtures for tests that walk a student through the academic record:
 * users per role, a program curriculum, a student, and the registrar actions
 * the UI actually uses (guided next-term enrollment and bulk grade entry).
 */
trait BuildsAcademicRecords
{
    /** @var array<string, Subject> */
    protected array $subjects = [];

    /** @var array<string, Curriculum> */
    protected array $curricula = [];

    protected function seedRoles(): void
    {
        foreach (['staff', 'admin', 'student'] as $role) {
            Role::findOrCreate($role, 'api');
        }
    }

    protected function makeUser(string $role, ?string $username = null, string $password = 'secret-pass'): User
    {
        $username ??= $role . '01';

        $user = User::create([
            'name'     => ucfirst($role) . ' User',
            'email'    => $username . '@tmcc.test',
            'username' => $username,
            'role'     => $role,
            'password' => $password, // hashed by the model cast
        ]);

        $user->assignRole($role);

        return $user;
    }

    protected function makeProgram(string $code = 'BSIT', string $name = 'BS Information Technology'): Program
    {
        return Program::create(['code' => $code, 'name' => $name]);
    }

    /**
     * @param array<string, array{0: int, 1: int, 2?: int}> $defs  code => [year level, semester, units (default 3)]
     * @param array<string, string[]> $prerequisites              code => prerequisite codes
     */
    protected function makeCurriculum(Program $program, array $defs, array $prerequisites = []): void
    {
        foreach ($defs as $code => $spec) {
            $this->subjects[$code] = Subject::create([
                'code'  => $code,
                'title' => 'Subject ' . $code,
                'units' => $spec[2] ?? 3,
            ]);

            $this->curricula[$code] = Curriculum::create([
                'program_id' => $program->id,
                'subject_id' => $this->subjects[$code]->id,
                'year_level' => $spec[0],
                'semester'   => $spec[1],
            ]);
        }

        foreach ($prerequisites as $code => $required) {
            $this->curricula[$code]->prerequisites()->sync($this->idsFor($required));
        }
    }

    protected function makeStudent(Program $program, array $overrides = [], ?User $account = null): Student
    {
        return Student::create(array_merge([
            'user_id'         => $account?->id,
            'program_id'      => $program->id,
            'student_number'  => '2026-0001',
            'first_name'      => 'Juan',
            'last_name'       => 'Dela Cruz',
            'date_of_birth'   => '2005-01-01',
            'email'           => 'juan@tmcc.test',
            'sex'             => 'M',
            'enrollment_date' => '2026-06-01',
        ], $overrides));
    }

    /** Write a grade row directly, bypassing the enrollment flow. */
    protected function recordGrade(Student $student, string $code, string $academicYear, int $semester, ?float $value, string $status): Grade
    {
        return Grade::create([
            'student_id'    => $student->student_id,
            'subject_id'    => $this->subjects[$code]->id,
            'academic_year' => $academicYear,
            'semester'      => (string) $semester,
            'grade_value'   => $value,
            'status'        => $status,
        ]);
    }

    /**
     * @param string[] $codes
     * @return int[]
     */
    protected function idsFor(array $codes): array
    {
        return array_map(fn (string $code) => $this->subjects[$code]->id, $codes);
    }

    /** The guided next-term flow the Edit Student page uses. */
    protected function enrollNextTerm(Student $student, array $codes, array $retakeCodes = []): TestResponse
    {
        return $this->postJson("/api/staff/students/{$student->student_id}/enrollments/add-next-term", [
            'subject_ids'        => $this->idsFor($codes),
            'retake_subject_ids' => $this->idsFor($retakeCodes),
        ]);
    }

    /**
     * Bulk grade entry, as the Edit Student page submits it. Targets the most
     * recent grade row for each subject, so a retake is graded, not the
     * original attempt.
     *
     * @param array<string, float|string> $grades code => numeric grade, or a status such as 'INC'
     */
    protected function submitGrades(Student $student, array $grades): TestResponse
    {
        $rows = [];

        foreach ($grades as $code => $value) {
            $grade = Grade::where('student_id', $student->student_id)
                ->where('subject_id', $this->subjects[$code]->id)
                ->latest('id')
                ->firstOrFail();

            $rows[] = is_string($value)
                ? ['grade_id' => $grade->id, 'status' => $value, 'grade_value' => null]
                : ['grade_id' => $grade->id, 'grade_value' => $value];
        }

        return $this->putJson("/api/staff/students/{$student->student_id}/grades/bulk-update", ['grades' => $rows]);
    }

    protected function academicProgress(Student $student): array
    {
        return $this->getJson("/api/staff/students/{$student->student_id}/academic-progress")
            ->assertOk()
            ->json();
    }

    /** @return array<string, mixed> The available-subject row for $code in the next term. */
    protected function availableSubject(array $progress, string $code): array
    {
        foreach ($progress['available_subjects'] as $row) {
            if ($row['subject_code'] === $code) {
                return $row;
            }
        }

        $this->fail("{$code} is not in the next term's available subjects.");
    }
}
