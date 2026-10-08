<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\Grade;
use App\Models\PendingStudentUpdate;
use App\Models\RecordRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsTwoStudentRecords;
use Tests\TestCase;

/**
 * #58: who may call every endpoint behind student information, grades,
 * awards, documents, transcripts and slips.
 *
 *   registrar  reads and writes            admin  reads; every write 403
 *   student    own record only (A never sees B)   anonymous  401 JSON, never 500
 *
 * Record-request handling (approve, reject, release, transactions) stays open
 * to admins by design (RecordRequestFlowTest::test_admin_keeps_document_release).
 */
class DataAccessMatrixTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTwoStudentRecords;

    /** Reads open to the registrar and admins. {A} = student A's id, {P} = program, {E} = curriculum entry. */
    private const SHARED_READS = [
        ['GET', '/api/staff/students'],
        ['GET', '/api/staff/students/{A}'],
        ['GET', '/api/staff/students/{A}/academic-progress'],
        ['GET', '/api/staff/students/{A}/academic-summary'],
        ['GET', '/api/staff/students/{A}/transcript'],
        ['GET', '/api/staff/students/{A}/documents'],
        ['GET', '/api/staff/students/{A}/documents/{A.document}/download'],
        ['GET', '/api/staff/programs'],
        ['GET', '/api/staff/programs/{P}/curriculum'],
        ['GET', '/api/staff/curriculum/{E}/impact'],
        ['GET', '/api/staff/subjects'],
        ['GET', '/api/staff/subject-prefixes'],
        ['GET', '/api/staff/pending-profile-updates'],
        ['GET', '/api/staff/pending-profile-updates/{A.update}'],
        ['GET', '/api/staff/pending-profile-updates/{A.update}/supporting-document'],
        ['GET', '/api/staff/pending-requests'],
        ['GET', '/api/staff/approved-release'],
        ['GET', '/api/staff/rejected-requests'],
        ['GET', '/api/staff/appointment-slots'],
        ['GET', '/api/staff/requests/{A.request}/approval-slip'],
        ['GET', '/api/staff/requests/{A.request}/transcript-template'],
        ['GET', '/api/staff/reports/summary'],
        ['GET', '/api/staff/reports/transaction-history'],
    ];

    /** Every signed-in role: their own dashboard, the current term, their own account. */
    private const ANY_ROLE_READS = [
        ['GET', '/api/dashboard'],
        ['GET', '/api/settings/current'],
        ['GET', '/api/user'],
    ];

    /** Registrar-only reads (student numbers, #56). */
    private const REGISTRAR_READS = [
        ['GET', '/api/staff/student-numbers/check?number=260009'],
        ['GET', '/api/staff/student-numbers/mismatches'],
    ];

    /** Registrar-only writes. Ids that don't exist (999999) still prove access: 404/422, never 403. */
    private const REGISTRAR_WRITES = [
        ['POST', '/api/staff/students'],
        ['PUT', '/api/staff/students/{A}'],
        ['POST', '/api/staff/students/{A}/archive'],
        ['PUT', '/api/staff/students/{A}/archive-location'],
        ['PATCH', '/api/staff/students/{A}/program'],
        ['PATCH', '/api/staff/students/{A}/student-number'],
        ['POST', '/api/staff/students/{A}/documents'],
        ['DELETE', '/api/staff/students/{A}/documents/999999'],
        ['POST', '/api/staff/students/{A}/enrollments'],
        ['POST', '/api/staff/students/{A}/enrollments/add-next-term'],
        ['PUT', '/api/staff/students/{A}/enrollments/999999'],
        ['DELETE', '/api/staff/students/{A}/enrollments/999999'],
        ['POST', '/api/staff/students/{A}/grades'],
        ['PUT', '/api/staff/students/{A}/grades/bulk-update'],
        ['PUT', '/api/staff/students/{A}/grades/999999'],
        ['DELETE', '/api/staff/students/{A}/grades/999999'],
        ['PATCH', '/api/staff/pending-profile-updates/{A.update}/approve'],
        ['PATCH', '/api/staff/pending-profile-updates/{A.update}/reject'],
        ['PATCH', '/api/staff/pending-profile-updates/{A.update}/return'],
        ['POST', '/api/staff/subjects'],
        ['PUT', '/api/staff/subjects/999999'],
        ['DELETE', '/api/staff/subjects/999999'],
        ['PATCH', '/api/staff/subjects/999999/archive'],
        ['PATCH', '/api/staff/subjects/999999/unarchive'],
        ['POST', '/api/staff/programs'],
        ['PUT', '/api/staff/programs/999999'],
        ['DELETE', '/api/staff/programs/999999'],
        ['PATCH', '/api/staff/programs/999999/archive'],
        ['PATCH', '/api/staff/programs/999999/unarchive'],
        ['POST', '/api/staff/programs/999999/clone'],
        ['POST', '/api/staff/programs/999999/curriculum'],
        ['PATCH', '/api/staff/curriculum/999999'],
        ['DELETE', '/api/staff/curriculum/999999'],
        ['PUT', '/api/staff/curriculum/999999/prerequisites'],
        ['POST', '/api/staff/curriculums'],
    ];

    /** Record-request handling: registrar and admin (document release stays with admins). */
    private const SHARED_WRITES = [
        ['PATCH', '/api/staff/requests/999999/approve'],
        ['PATCH', '/api/staff/requests/999999/reject'],
        ['PUT', '/api/staff/requests/999999/release'],
        ['POST', '/api/staff/transactions'],
    ];

    /** Admin only. */
    private const ADMIN_ENDPOINTS = [
        ['GET', '/api/admin/users'],
        ['GET', '/api/admin/users/{registrar}'],
        ['GET', '/api/admin/logs'],
        ['GET', '/api/admin/logs/export-pdf'],
        ['GET', '/api/admin/reports/export'],
        ['GET', '/api/admin/reports/requests'],
        ['GET', '/api/admin/reports/activity'],
        ['GET', '/api/admin/settings'],
        ['POST', '/api/admin/users'],
        ['PUT', '/api/admin/users/999999'],
        ['DELETE', '/api/admin/users/999999'],
        ['PUT', '/api/admin/settings'],
    ];

    /** The student portal: always the signed-in student's own record. */
    private const STUDENT_READS = [
        ['GET', '/api/student/profile'],
        ['GET', '/api/student/academic-summary'],
        ['GET', '/api/student/cor'],
        ['GET', '/api/student/curriculum'],
        ['GET', '/api/student/grades'],
        ['GET', '/api/student/subjects'],
        ['GET', '/api/student/record-requests'],
        ['GET', '/api/student/record-requests/{A.request}'],
        ['GET', '/api/student/record-requests/{A.request}/approval-slip'],
        ['GET', '/api/student/record-requests/{A.request}/transcript'],
        ['GET', '/api/student/profile-updates'],
    ];

    private const STUDENT_WRITES = [
        ['POST', '/api/student/record-requests'],
        ['PUT', '/api/student/sis'],
        ['POST', '/api/student/sis'],
        ['POST', '/api/student/profile-updates/{A.update}/resubmit'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTwoStudentRecords();
    }

    private function uri(string $uri): string
    {
        $uri = strtr($uri, [
            '{P}' => (string) $this->program->id,
            '{E}' => (string) Curriculum::where('program_id', $this->program->id)->value('id'),
            '{registrar}' => (string) $this->registrar->id,
        ]);

        return $this->fillUri($uri);
    }

    /** One request with an empty body, with or without Accept: application/json. */
    private function hit(string $method, string $uri, bool $json = true)
    {
        return $this->call($method, $this->uri($uri), [], [], [], $json ? ['HTTP_ACCEPT' => 'application/json'] : []);
    }

    /** Runs every endpoint and returns "METHOD uri -> status" for each that broke the rule. */
    private function violations(array $endpoints, callable $ok, bool $json = true): array
    {
        $bad = [];
        foreach ($endpoints as [$method, $uri]) {
            $status = $this->hit($method, $uri, $json)->getStatusCode();
            if (! $ok($status)) {
                $bad[] = "{$method} {$uri} -> {$status}";
            }
        }

        return $bad;
    }

    private function allEndpoints(): array
    {
        return array_merge(self::SHARED_READS, self::ANY_ROLE_READS, self::REGISTRAR_READS, self::REGISTRAR_WRITES, self::SHARED_WRITES,
            self::ADMIN_ENDPOINTS, self::STUDENT_READS, self::STUDENT_WRITES,
            [['POST', '/api/auth/logout'], ['POST', '/api/auth/change-password']]);
    }

    // ------------------------------------------------------------ registrar

    public function test_the_registrar_reads_and_writes_student_records(): void
    {
        Sanctum::actingAs($this->registrar, ['*']);

        $this->assertSame([], $this->violations(array_merge(self::SHARED_READS, self::ANY_ROLE_READS, self::REGISTRAR_READS), fn ($s) => $s === 200));
        $this->assertSame([], $this->violations(array_merge(self::REGISTRAR_WRITES, self::SHARED_WRITES), fn ($s) => ! in_array($s, [401, 403], true)));
    }

    public function test_the_registrar_is_refused_admin_and_student_portal_endpoints(): void
    {
        Sanctum::actingAs($this->registrar, ['*']);

        $this->assertSame([], $this->violations(array_merge(self::ADMIN_ENDPOINTS, self::STUDENT_READS, self::STUDENT_WRITES), fn ($s) => $s === 403));
    }

    // ---------------------------------------------------------------- admin

    public function test_admins_read_but_every_record_write_is_refused(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->assertSame([], $this->violations(array_merge(self::SHARED_READS, self::ANY_ROLE_READS), fn ($s) => $s === 200));
        $this->assertSame([], $this->violations(array_merge(self::REGISTRAR_READS, self::REGISTRAR_WRITES, self::STUDENT_READS, self::STUDENT_WRITES), fn ($s) => $s === 403));
        // Document release stays with admins by design.
        $this->assertSame([], $this->violations(self::SHARED_WRITES, fn ($s) => ! in_array($s, [401, 403], true)));
        $this->assertSame([], $this->violations(self::ADMIN_ENDPOINTS, fn ($s) => ! in_array($s, [401, 403], true)));
        // The reports answer on SQLite since #84 (the export was a 500 here).
        $this->assertSame([], $this->violations([['GET', '/api/admin/reports/export'], ['GET', '/api/admin/reports/requests'], ['GET', '/api/admin/reports/activity']], fn ($s) => $s === 200));
    }

    // -------------------------------------------------------------- student

    public function test_a_student_reads_only_their_own_record(): void
    {
        Sanctum::actingAs($this->people['A']['user'], ['*']);

        $this->assertSame([], $this->violations(array_merge(self::STUDENT_READS, self::ANY_ROLE_READS), fn ($s) => $s === 200));
        $this->assertSame([], $this->violations(
            array_merge(self::SHARED_READS, self::REGISTRAR_READS, self::REGISTRAR_WRITES, self::SHARED_WRITES, self::ADMIN_ENDPOINTS),
            fn ($s) => $s === 403,
        ));
    }

    public function test_changing_an_id_in_the_url_never_reaches_another_student(): void
    {
        Sanctum::actingAs($this->people['A']['user'], ['*']);

        // B's request, slip and transcript by id: not found for A.
        $this->assertSame([], $this->violations([
            ['GET', '/api/student/record-requests/{B.request}'],
            ['GET', '/api/student/record-requests/{B.request}/approval-slip'],
            ['GET', '/api/student/record-requests/{B.request}/transcript'],
            ['POST', '/api/student/profile-updates/{B.update}/resubmit'],
        ], fn ($s) => in_array($s, [403, 404], true)));

        // B's record through the staff endpoints: forbidden.
        $this->assertSame([], $this->violations([
            ['GET', '/api/staff/students/{B}'],
            ['GET', '/api/staff/students/{B}/academic-summary'],
            ['GET', '/api/staff/students/{B}/transcript'],
            ['GET', '/api/staff/students/{B}/documents'],
            ['GET', '/api/staff/students/{B}/documents/{B.document}/download'],
            ['GET', '/api/staff/requests/{B.request}/approval-slip'],
        ], fn ($s) => $s === 403));

        // A's own listings contain nothing of B.
        foreach (['/api/student/record-requests', '/api/student/profile', '/api/student/grades', '/api/student/academic-summary', '/api/student/profile-updates'] as $uri) {
            $body = $this->getJson($uri)->assertOk()->getContent();
            $this->assertStringNotContainsString(self::PERSONAL['B']['last'], $body, $uri);
            $this->assertStringNotContainsString(self::PERSONAL['B']['number'], $body, $uri);
        }
    }

    public function test_changing_an_id_in_the_body_never_reaches_another_student(): void
    {
        $a = $this->people['A']['student'];
        $b = $this->people['B']['student'];
        Sanctum::actingAs($this->people['A']['user'], ['*']);

        // A request "for B" is filed under A.
        $id = $this->postJson('/api/student/record-requests', [
            'record_type' => 'certificate_of_grades', 'purpose' => 'Scholarship', 'student_id' => $b->student_id,
        ])->assertCreated()->json('record_request.id');
        $this->assertSame($a->student_id, RecordRequest::findOrFail($id)->student_id);

        // An SIS update "for B" changes nothing of B's.
        $bUpdates = PendingStudentUpdate::where('student_id', $b->student_id)->count();
        $this->postJson('/api/student/sis', [
            'student_id' => $b->student_id, 'user_id' => $this->people['B']['user']->id, 'contact_number' => '09999999999',
        ]);
        $this->assertSame($bUpdates, PendingStudentUpdate::where('student_id', $b->student_id)->count());
        $this->assertSame(self::PERSONAL['B']['contact'], $b->fresh()->contact_number);
        $this->assertSame(2, Grade::where('student_id', $b->student_id)->count());
    }

    // ------------------------------------------------------------ anonymous

    public function test_anonymous_requests_get_401_json_with_and_without_the_json_header(): void
    {
        foreach ([true, false] as $json) {
            $bad = [];
            foreach ($this->allEndpoints() as [$method, $uri]) {
                $response = $this->hit($method, $uri, $json);
                $status = $response->getStatusCode();
                $isJson401 = $status === 401 && $response->json('message') === 'Unauthenticated.';
                if (! $isJson401) {
                    $bad[] = "{$method} {$uri} -> {$status}";
                }
                $this->app['auth']->forgetGuards();
            }
            $this->assertSame([], $bad, $json ? 'with Accept: application/json' : 'without Accept: application/json');
        }
    }

    // -------------------------------------------------------- signed links

    public function test_the_public_appointment_page_needs_its_own_signed_link(): void
    {
        $a = $this->people['A']['request'];
        $b = $this->people['B']['request'];
        $signedA = URL::signedRoute('appointment.public.form', ['id' => $a->id]);

        $this->get($signedA)->assertOk()->assertSee(self::PERSONAL['A']['last'], false);
        $this->get(route('appointment.public.form', ['id' => $a->id]))->assertForbidden();
        // A's signature on B's id is refused.
        $this->get(str_replace("/form/{$a->id}?", "/form/{$b->id}?", $signedA))->assertForbidden();
    }
}
