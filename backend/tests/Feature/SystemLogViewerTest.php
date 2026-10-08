<?php

namespace Tests\Feature;

use App\Models\SystemLog;
use App\Models\User;
use App\Services\SystemLogReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * #94: the admin log is newest first with names, roles and Manila time,
 * filtered by user, role, action text and dates on the server, and the PDF
 * uses the same filters. A registrar reads only their own activity.
 */
class SystemLogViewerTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicRecords;

    private User $admin;

    private User $registrar;

    private User $otherRegistrar;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->admin = $this->makeUser('admin', 'uat.admin');
        $this->admin->forceFill(['name' => 'Ada Admin'])->save();
        $this->registrar = $this->makeUser('staff', 'uat.staff');
        $this->registrar->forceFill(['name' => 'Rita Registrar'])->save();
        $this->otherRegistrar = $this->makeUser('staff', 'other.staff');
        $this->otherRegistrar->forceFill(['name' => 'Omar Other'])->save();
        $this->student = $this->makeUser('student', '260001');

        // Out of order on purpose; times are Manila wall-clock as stored.
        $this->log('Login: uat.staff', $this->registrar, '2026-10-05 08:00:00');
        $this->log('Subject created: IT101 — Intro', $this->registrar, '2026-10-06 23:59:59');
        $this->log('Settings changed: academic year 2025-2026 → 2026-2027', $this->admin, '2026-10-07 09:15:30');
        $this->log('Login: other.staff', $this->otherRegistrar, '2026-10-07 00:00:00');
        $this->log("Failed login: unknown user 'adm1n'", null, '2026-10-04 12:00:00', 'guest');
        $this->log('Grade 100% corrected for 260001', $this->otherRegistrar, '2026-10-06 10:00:00');
    }

    private function log(string $action, ?User $user, string $at, ?string $role = null): SystemLog
    {
        $log = SystemLog::create(['action' => $action, 'user_id' => $user?->id, 'role' => $role ?? $user?->role ?? 'system']);
        $log->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $log;
    }

    private function adminLog(array $query = [])
    {
        Sanctum::actingAs($this->admin, ['*']);

        return $this->getJson('/api/admin/logs?' . http_build_query($query))->assertOk();
    }

    private function actions($response): array
    {
        return array_column($response->json('data'), 'action');
    }

    // --------------------------------------------------------------- admin

    public function test_the_log_is_newest_first_with_names_roles_and_manila_time(): void
    {
        $response = $this->adminLog();

        $this->assertSame([
            'Settings changed: academic year 2025-2026 → 2026-2027',
            'Login: other.staff',
            'Subject created: IT101 — Intro',
            'Grade 100% corrected for 260001',
            'Login: uat.staff',
            "Failed login: unknown user 'adm1n'",
        ], $this->actions($response));

        $response->assertJsonPath('data.0.user_name', 'Ada Admin')
            ->assertJsonPath('data.0.username', 'uat.admin')
            ->assertJsonPath('data.0.role', 'admin')
            ->assertJsonPath('data.0.logged_at', '2026-10-07 09:15:30')
            ->assertJsonPath('data.0.logged_at_label', 'Oct 7, 2026 9:15:30 AM')
            ->assertJsonPath('data.5.user_name', null)
            ->assertJsonPath('data.5.role', 'guest');
    }

    public function test_rows_logged_in_the_same_second_are_newest_first_by_id(): void
    {
        $first = $this->log('First', $this->admin, '2026-10-09 10:00:00');
        $second = $this->log('Second', $this->admin, '2026-10-09 10:00:00');

        $this->assertSame([$second->log_id, $first->log_id], array_slice(array_column($this->adminLog()->json('data'), 'log_id'), 0, 2));
    }

    public function test_filter_by_user(): void
    {
        $this->assertSame(
            ['Login: other.staff', 'Grade 100% corrected for 260001'],
            $this->actions($this->adminLog(['user_id' => $this->otherRegistrar->id])),
        );
    }

    public function test_filter_by_role(): void
    {
        $this->assertSame(["Failed login: unknown user 'adm1n'"], $this->actions($this->adminLog(['role' => 'guest'])));
        $this->assertCount(4, $this->adminLog(['role' => 'staff'])->json('data'));
    }

    public function test_filter_by_action_text_treats_wildcards_literally(): void
    {
        $this->assertSame(['Login: other.staff'], $this->actions($this->adminLog(['q' => ' LOGIN: OTHER '])));
        $this->assertCount(3, $this->adminLog(['q' => 'login'])->json('data'));
        $this->assertSame(['Grade 100% corrected for 260001'], $this->actions($this->adminLog(['q' => '100%'])));
        $this->assertSame([], $this->actions($this->adminLog(['q' => '_ogin'])));
    }

    public function test_filter_by_date_range_includes_both_whole_days(): void
    {
        $this->assertSame(
            ['Subject created: IT101 — Intro', 'Grade 100% corrected for 260001'],
            $this->actions($this->adminLog(['date_from' => '2026-10-06', 'date_to' => '2026-10-06'])),
        );
        $this->assertSame(
            ['Settings changed: academic year 2025-2026 → 2026-2027', 'Login: other.staff'],
            $this->actions($this->adminLog(['date_from' => '2026-10-07'])),
        );
        $this->assertCount(2, $this->adminLog(['date_to' => '2026-10-05'])->json('data'));
    }

    public function test_filters_combine(): void
    {
        $this->assertSame(['Grade 100% corrected for 260001'], $this->actions($this->adminLog([
            'user_id' => $this->otherRegistrar->id, 'role' => 'staff', 'q' => 'grade', 'date_from' => '2026-10-06', 'date_to' => '2026-10-06',
        ])));
    }

    public function test_bad_filters_are_refused(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->getJson('/api/admin/logs?date_from=2026-10-07&date_to=2026-10-01')->assertStatus(422)->assertJsonValidationErrors('date_to');
        $this->getJson('/api/admin/logs?role=hacker')->assertStatus(422)->assertJsonValidationErrors('role');
        $this->getJson('/api/admin/logs?date_from=07/10/2026')->assertStatus(422);
    }

    public function test_paging_is_done_on_the_server(): void
    {
        foreach (range(1, 6) as $i) {
            $this->log("Extra {$i}", $this->admin, '2026-10-01 08:00:0' . $i);
        }

        $this->adminLog(['per_page' => 5])->assertJsonPath('total', 12)->assertJsonPath('last_page', 3)->assertJsonCount(5, 'data');
        $this->adminLog(['per_page' => 5, 'page' => 3])->assertJsonCount(2, 'data')->assertJsonPath('data.1.action', 'Extra 1');
    }

    public function test_the_user_filter_lists_the_people_in_the_log(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $users = $this->getJson('/api/admin/logs/users')->assertOk()->json('users');

        $this->assertSame(['Ada Admin', 'Omar Other', 'Rita Registrar'], array_column($users, 'name'));
        $this->assertSame(['id', 'name', 'username', 'role'], array_keys($users[0]));
    }

    public function test_the_pdf_export_uses_the_filters_and_prints_them(): void
    {
        $spy = new class extends SystemLogReport {
            public ?string $html = null;

            public function pdfHtml(iterable $rows, string $filterSummary, int $total): string
            {
                return $this->html = parent::pdfHtml($rows, $filterSummary, $total);
            }
        };
        $this->app->instance(SystemLogReport::class, $spy);
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->get('/api/admin/logs/export-pdf?' . http_build_query([
            'user_id' => $this->registrar->id, 'q' => 'subject', 'date_from' => '2026-10-06', 'date_to' => '2026-10-07',
        ]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $response->streamedContent());

        $this->assertStringContainsString('Filters: User: Rita Registrar (uat.staff) · Action contains: &quot;subject&quot; · Dates: 2026-10-06 to 2026-10-07', $spy->html);
        $this->assertStringContainsString('Total entries: 1', $spy->html);
        $this->assertStringContainsString('Subject created: IT101 — Intro', $spy->html);
        $this->assertStringContainsString('2026-10-06 23:59:59', $spy->html);
        $this->assertStringNotContainsString('Login: uat.staff', $spy->html);
        $this->assertStringNotContainsString('Settings changed', $spy->html);
    }

    public function test_the_pdf_export_without_filters_says_so(): void
    {
        $html = app(SystemLogReport::class)->pdfHtml([], app(SystemLogReport::class)->describeFilters([]), 0);

        $this->assertStringContainsString('Filters: None (all entries)', $html);
        $this->assertStringContainsString('No log entries match these filters.', $html);
    }

    public function test_the_admin_log_is_admin_only(): void
    {
        foreach ([$this->registrar, $this->student] as $user) {
            Sanctum::actingAs($user, ['*']);
            $this->getJson('/api/admin/logs')->assertForbidden();
            $this->getJson('/api/admin/logs/users')->assertForbidden();
            $this->get('/api/admin/logs/export-pdf', ['Accept' => 'application/json'])->assertForbidden();
        }
    }

    // --------------------------------------------------------- my activity

    public function test_a_registrar_sees_only_their_own_activity(): void
    {
        Sanctum::actingAs($this->registrar, ['*']);

        $response = $this->getJson('/api/staff/my-activity')->assertOk();
        $this->assertSame(['Subject created: IT101 — Intro', 'Login: uat.staff'], $this->actions($response));
        $response->assertJsonPath('data.0.user_name', 'Rita Registrar')->assertJsonPath('data.0.logged_at', '2026-10-06 23:59:59');

        // Asking for someone else's rows changes nothing.
        $this->assertSame(
            ['Subject created: IT101 — Intro', 'Login: uat.staff'],
            $this->actions($this->getJson('/api/staff/my-activity?user_id=' . $this->otherRegistrar->id)->assertOk()),
        );
    }

    public function test_my_activity_takes_the_same_filters(): void
    {
        Sanctum::actingAs($this->registrar, ['*']);

        $this->assertSame(['Login: uat.staff'], $this->actions($this->getJson('/api/staff/my-activity?q=login')));
        $this->assertSame(['Subject created: IT101 — Intro'], $this->actions($this->getJson('/api/staff/my-activity?date_from=2026-10-06')));
        $this->getJson('/api/staff/my-activity?per_page=5')->assertJsonPath('per_page', 5)->assertJsonPath('total', 2);
        $this->getJson('/api/staff/my-activity?date_from=2026-10-07&date_to=2026-10-01')->assertStatus(422);
    }

    public function test_students_cannot_read_any_log(): void
    {
        Sanctum::actingAs($this->student, ['*']);

        $this->getJson('/api/staff/my-activity')->assertForbidden();
        $this->getJson('/api/admin/logs')->assertForbidden();
    }
}
