<?php

namespace Tests\Feature;

use App\Models\RecordRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #93: Request Document created a request at once, with no purpose. The
 * student now fills in a purpose (required, up to 255 characters) and the
 * number of copies (1-10), and the registrar sees the purpose.
 */
class RecordRequestPurposeTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private User $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->account = $this->makeUser('student', '260001');
        $this->makeStudent($this->makeProgram(), ['student_number' => '260001'], $this->account);
        Sanctum::actingAs($this->account, ['*']);
    }

    private function submit(array $body)
    {
        return $this->postJson('/api/student/record-requests', ['record_type' => 'transcript'] + $body);
    }

    public function test_a_request_without_a_purpose_is_refused_and_not_saved(): void
    {
        foreach ([[], ['purpose' => ''], ['purpose' => '   ']] as $body) {
            $this->submit($body)
                ->assertStatus(422)
                ->assertJsonPath('errors.purpose.0', 'Enter the purpose of the request, e.g. employment or scholarship.');
        }

        $this->assertSame(0, RecordRequest::count());
    }

    public function test_the_purpose_and_copies_are_limited(): void
    {
        $this->submit(['purpose' => str_repeat('a', 256)])->assertStatus(422)->assertJsonValidationErrors('purpose');
        $this->submit(['purpose' => 'Employment', 'copies' => 0])->assertStatus(422)->assertJsonPath('errors.copies.0', 'Request at least 1 copy.');
        $this->submit(['purpose' => 'Employment', 'copies' => 11])->assertStatus(422)->assertJsonPath('errors.copies.0', 'You can request at most 10 copies.');

        $this->assertSame(0, RecordRequest::count());
    }

    public function test_purpose_and_copies_are_saved_and_shown_to_the_registrar(): void
    {
        $this->submit(['purpose' => 'Scholarship application', 'copies' => 3])
            ->assertCreated()
            ->assertJsonPath('record_request.purpose', 'Scholarship application')
            ->assertJsonPath('record_request.copies', 3);

        Sanctum::actingAs($this->makeUser('staff'), ['*']);
        $this->assertStringContainsString('Scholarship application', $this->getJson('/api/staff/pending-requests')->assertOk()->getContent());
    }

    public function test_copies_default_to_one(): void
    {
        $this->submit(['purpose' => 'Employment'])->assertCreated()->assertJsonPath('record_request.copies', 1);
    }
}
