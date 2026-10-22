<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Student;
use App\Support\ContactRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #105 (TMCC IT, 21 Oct 2026): contact numbers are Philippine mobile numbers,
 * typed as 09XXXXXXXXX or +639XXXXXXXXX and stored as 09XXXXXXXXX; e-mail
 * addresses need a real domain.
 */
class ContactValidationTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->program = $this->makeProgram('BSTM', 'BS Tourism Management');
    }

    private function newStudent(array $overrides = [])
    {
        Sanctum::actingAs($this->makeUser('staff'), ['*']);

        return $this->postJson('/api/staff/students', array_merge([
            'student_number'  => '0004',
            'first_name'      => 'Bea',
            'last_name'       => 'Santos',
            'date_of_birth'   => '2006-03-03',
            'email'           => 'bea@tmcc.test',
            'sex'             => 'F',
            'enrollment_date' => '2026-08-10',
            'program_id'      => $this->program->id,
            'record_type'     => 'Form 137',
            'cabinet_no'      => 'C1',
            'shelf_no'        => 'S1',
            'folder_code'     => 'F1',
            'document_status' => 'Complete',
        ], $overrides));
    }

    /**
     * @dataProvider acceptedNumbers
     */
    public function test_a_mobile_number_is_stored_as_09_and_10_digits(string $typed): void
    {
        $this->newStudent(['contact_number' => $typed])->assertCreated();

        $this->assertSame('09171234567', Student::where('student_number', '260004')->value('contact_number'));
    }

    public static function acceptedNumbers(): array
    {
        return [
            'plain 09'         => ['09171234567'],
            '09 with spaces'   => ['0917 123 4567'],
            '+63'              => ['+639171234567'],
            '+63 with dashes'  => ['+63 917-123-4567'],
        ];
    }

    /**
     * @dataProvider refusedNumbers
     */
    public function test_a_number_that_is_not_a_mobile_number_is_refused(string $typed): void
    {
        $this->newStudent(['contact_number' => $typed])
            ->assertStatus(422)
            ->assertJsonPath('errors.contact_number.0', ContactRules::MOBILE_MESSAGE);

        $this->assertSame(0, Student::count());
    }

    public static function refusedNumbers(): array
    {
        return [
            '10 digits'          => ['0999999999'],
            '12 digits'          => ['091712345678'],
            'landline'           => ['046 123 4567'],
            'foreign'            => ['+1 555 123 4567'],
            'letters'            => ['0917abc4567'],
        ];
    }

    public function test_the_contact_number_stays_optional(): void
    {
        $this->newStudent(['contact_number' => ''])->assertCreated();

        $this->assertNull(Student::where('student_number', '260004')->value('contact_number'));
    }

    public function test_an_email_without_a_domain_is_refused(): void
    {
        $this->newStudent(['email' => 'bea@gmail'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', ContactRules::EMAIL_MESSAGE);
    }

    public function test_the_student_information_sheet_checks_the_number_too(): void
    {
        $account = $this->makeUser('student', '260001');
        $this->makeStudent($this->program, ['student_number' => '260001'], $account);
        Sanctum::actingAs($account, ['*']);

        $this->postJson('/api/student/sis', ['contact_number' => '12345'])
            ->assertStatus(422)
            ->assertJsonPath('errors.contact_number.0', ContactRules::MOBILE_MESSAGE);
    }

    public function test_a_staff_account_needs_a_real_email(): void
    {
        Sanctum::actingAs($this->makeUser('admin'), ['*']);

        $this->postJson('/api/admin/users', [
            'name'                  => 'New Staff',
            'username'              => 'new.staff',
            'email'                 => 'new.staff@tmcc',
            'role'                  => 'staff',
            'password'              => 'strong-pass-2026',
            'password_confirmation' => 'strong-pass-2026',
        ])->assertStatus(422)->assertJsonPath('errors.email.0', ContactRules::EMAIL_MESSAGE);
    }
}
