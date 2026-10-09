<?php

namespace Tests\Feature;

use App\Services\AcademicStandingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Concerns\SeedsTwoStudentRecords;
use Tests\TestCase;

/**
 * #58: what student data responses contain. No password hashes, tokens,
 * stored file paths, server paths, SQL or stack traces for anyone; a student
 * never sees another student's data; error bodies are generic when
 * APP_DEBUG is off.
 */
class ResponseContentsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTwoStudentRecords;

    /** JSON reads, by who may call them. */
    private const STAFF_JSON_READS = [
        '/api/staff/students', '/api/staff/students/{A}', '/api/staff/students/{A}/academic-progress',
        '/api/staff/students/{A}/academic-summary', '/api/staff/students/{A}/documents', '/api/staff/programs',
        '/api/staff/subjects', '/api/staff/subject-prefixes', '/api/staff/pending-profile-updates',
        '/api/staff/pending-profile-updates/{A.update}', '/api/staff/pending-requests', '/api/staff/approved-release',
        '/api/staff/rejected-requests', '/api/staff/reports/summary',
        '/api/staff/reports/transaction-history', '/api/dashboard', '/api/settings/current', '/api/user',
    ];

    private const STUDENT_JSON_READS = [
        '/api/student/profile', '/api/student/academic-summary', '/api/student/cor', '/api/student/curriculum',
        '/api/student/grades', '/api/student/subjects', '/api/student/record-requests', '/api/student/record-requests/{A.request}',
        '/api/dashboard', '/api/settings/current', '/api/user',
    ];

    private const ADMIN_JSON_READS = ['/api/admin/users', '/api/admin/logs', '/api/admin/settings'];

    /** Keys that must never appear in a response, at any depth. */
    private const FORBIDDEN_KEYS = [
        'password', 'remember_token', 'api_token', 'token', 'two_factor_secret',
        'file_path', 'supporting_document_path',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTwoStudentRecords();
    }

    /** Problems found in one response body. */
    private function problems(string $uri, string $body, array $mustNotContain = []): array
    {
        $found = [];
        $walk = function ($value, string $path) use (&$walk, &$found, $uri) {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $child) {
                if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                    $found[] = "{$uri}: key {$path}{$key}";
                }
                $walk($child, "{$path}{$key}.");
            }
        };
        $walk(json_decode($body, true), '');

        $patterns = [
            'bcrypt hash'  => '/\$2[aby]\$\d{2}\$/',
            'server path'  => '#' . preg_quote(str_replace('\\', '/', base_path()), '#') . '#i',
            'storage path' => '#(pending-profile-updates|student-documents)/#',
            'SQL'          => '/SQLSTATE|select \* from|insert into/i',
            'stack trace'  => '/Stack trace|#\d+ [A-Z]:|\.php:\d+|vendor[\/\\\\]laravel/i',
        ];
        $normalised = str_replace(['\\/', '\\\\'], ['/', '/'], $body);
        foreach ($patterns as $what => $pattern) {
            if (preg_match($pattern, $normalised)) {
                $found[] = "{$uri}: {$what}";
            }
        }
        foreach ($mustNotContain as $label => $value) {
            if (str_contains($body, $value)) {
                $found[] = "{$uri}: {$label}";
            }
        }

        return $found;
    }

    private function scan(array $uris, array $mustNotContain = []): array
    {
        $found = [];
        foreach ($uris as $uri) {
            $response = $this->getJson($this->fillUri($uri));
            $this->assertSame(200, $response->getStatusCode(), $uri);
            $found = array_merge($found, $this->problems($uri, $response->getContent(), $mustNotContain));
        }

        return $found;
    }

    /** Student B's personal values, which student A must never receive. */
    private function personalOf(string $key): array
    {
        $p = self::PERSONAL[$key];

        return [
            "{$key} last name" => $p['last'], "{$key} email" => $p['email'], "{$key} number" => $p['number'],
            "{$key} address" => $p['address'], "{$key} contact" => $p['contact'], "{$key} birth date" => $p['dob'],
        ];
    }

    public function test_registrar_responses_hold_no_secrets_or_paths(): void
    {
        Sanctum::actingAs($this->registrar, ['*']);

        $this->assertSame([], $this->scan(self::STAFF_JSON_READS));
    }

    public function test_admin_responses_hold_no_secrets_or_paths(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->assertSame([], $this->scan(array_merge(self::STAFF_JSON_READS, self::ADMIN_JSON_READS)));
    }

    public function test_a_student_receives_nothing_of_another_student(): void
    {
        Sanctum::actingAs($this->people['A']['user'], ['*']);

        $this->assertSame([], $this->scan(self::STUDENT_JSON_READS, $this->personalOf('B')));
    }

    public function test_the_student_dashboard_holds_only_the_students_own_data(): void
    {
        Sanctum::actingAs($this->people['A']['user'], ['*']);

        $body = $this->getJson('/api/dashboard')->assertOk()->getContent();
        $this->assertSame([], $this->problems('/api/dashboard', $body, $this->personalOf('B') + [
            'registrar email' => $this->registrar->email, 'admin email' => $this->admin->email,
        ]));
    }

    public function test_profile_updates_still_show_their_attachment_without_its_path(): void
    {
        Sanctum::actingAs($this->registrar, ['*']);
        $update = $this->people['A']['update'];

        $this->getJson("/api/staff/pending-profile-updates/{$update->id}")
            ->assertOk()
            ->assertJsonPath('has_supporting_document', true)
            ->assertJsonPath('supporting_document_original_name', 'proof-A.pdf')
            ->assertJsonMissingPath('supporting_document_path');
        $this->get("/api/staff/pending-profile-updates/{$update->id}/supporting-document")->assertOk()->assertDownload('proof-A.pdf');
    }

    public function test_errors_are_generic_when_debug_is_off(): void
    {
        config(['app.debug' => false]);
        $leaky = 'SQLSTATE[HY000]: General error (Connection: sqlite, Database: ' . base_path('database/database.sqlite') . ', SQL: select * from grades)';
        $this->mock(AcademicStandingService::class, fn ($mock) => $mock
            ->shouldReceive('getAcademicSummary')
            ->andThrow(new QueryException('sqlite', 'select * from grades', [], new RuntimeException($leaky))));

        foreach ([[$this->registrar, '/api/staff/students/{A}/academic-summary'], [$this->people['A']['user'], '/api/student/academic-summary']] as [$user, $uri]) {
            Sanctum::actingAs($user, ['*']);
            $response = $this->getJson($this->fillUri($uri));

            $response->assertStatus(500);
            $this->assertSame([], $this->problems($uri, $response->getContent(), ['database file' => 'database.sqlite']), $response->getContent());
            $this->assertNull($response->json('error'));
        }
    }
}
