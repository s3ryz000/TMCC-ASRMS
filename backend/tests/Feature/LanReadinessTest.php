<?php

namespace Tests\Feature;

use App\Models\RecordRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\SeedsTwoStudentRecords;
use Tests\TestCase;

/**
 * #22: the system runs on an isolated campus LAN. Slips and transcripts
 * render with everything embedded, and no page or PDF loads an asset from
 * the internet.
 */
class LanReadinessTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTwoStudentRecords;

    /**
     * An asset the browser or Dompdf would fetch: src=, <link href> (stylesheets,
     * fonts, icons), url() or @import pointing at http(s). Plain <a href> links to
     * other sites are not fetched and don't count.
     */
    private const EXTERNAL_ASSET = '#src\s*=\s*["\']https?://|<link\b[^>]*\bhref\s*=\s*["\']https?://|url\(\s*["\']?https?://|@import\s+["\']?(?:url\()?["\']?https?://#i';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seedTwoStudentRecords();
    }

    /** Number of embedded images in a PDF. */
    private function imagesIn(string $pdf): int
    {
        return preg_match_all('#/Subtype\s*/Image#', $pdf);
    }

    public function test_the_approval_slip_renders_with_its_qr_embedded(): void
    {
        $request = $this->people['A']['request'];
        $this->assertSame(RecordRequest::STATUS_RELEASED, $request->status);

        Sanctum::actingAs($this->registrar, ['*']);
        $staff = $this->get("/api/staff/requests/{$request->id}/approval-slip")->assertOk();
        Sanctum::actingAs($this->people['A']['user'], ['*']);
        $student = $this->get("/api/student/record-requests/{$request->id}/approval-slip")->assertOk();

        foreach ([$staff, $student] as $response) {
            $pdf = $response->streamedContent();
            $this->assertStringStartsWith('%PDF-', $pdf);
            $this->assertGreaterThanOrEqual(1, $this->imagesIn($pdf), 'the QR code is embedded in the PDF');
        }
    }

    public function test_the_transcript_renders_without_the_network(): void
    {
        Sanctum::actingAs($this->registrar, ['*']);
        $pdf = $this->get("/api/staff/students/{$this->people['A']['student']->student_id}/transcript")->assertOk()->streamedContent();
        $this->assertStringStartsWith('%PDF-', $pdf);

        Sanctum::actingAs($this->people['A']['user'], ['*']);
        $own = $this->get("/api/student/record-requests/{$this->people['A']['request']->id}/transcript")->assertOk()->streamedContent();
        $this->assertStringStartsWith('%PDF-', $own);
    }

    public function test_the_server_pages_load_nothing_from_the_internet(): void
    {
        $this->get('/')->assertOk()->assertDontSee('http://', false)->assertDontSee('https://', false);

        $signed = \Illuminate\Support\Facades\URL::signedRoute('appointment.public.form', ['id' => $this->people['A']['request']->id]);
        $page = $this->get($signed)->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression(self::EXTERNAL_ASSET, $page);
    }

    public function test_no_source_file_references_an_external_asset(): void
    {
        $roots = [base_path('app'), base_path('resources'), base_path('../frontend/src'), base_path('../frontend/public')];
        $files = (new Finder())->files()->in(array_filter($roots, 'is_dir'))
            ->name(['*.php', '*.js', '*.jsx', '*.css', '*.html', '*.json'])
            ->notName(['*.test.js', 'manifest.json']);

        $hits = [];
        foreach ($files as $file) {
            if (preg_match(self::EXTERNAL_ASSET, $file->getContents(), $m)) {
                $hits[] = $file->getRelativePathname() . ': ' . $m[0];
            }
        }

        $this->assertSame([], $hits);
    }
}
