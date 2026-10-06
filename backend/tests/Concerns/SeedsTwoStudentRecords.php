<?php

namespace Tests\Concerns;

use App\Models\ArchiveRecord;
use App\Models\Enrollment;
use App\Models\PendingStudentUpdate;
use App\Models\Program;
use App\Models\RecordRequest;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Two students with full records (#58): grades, enrollments, an archive
 * location, an uploaded document, an approved record request and a pending
 * profile update each, plus a registrar and an admin. Every personal value is
 * distinct so a test can tell whose data a response contains.
 */
trait SeedsTwoStudentRecords
{
    use BuildsAcademicRecords;

    protected User $registrar;
    protected User $admin;
    protected Program $program;
    /** @var array<string, array{student: Student, user: User, request: RecordRequest, document: StudentDocument, update: PendingStudentUpdate}> */
    protected array $people = [];

    /** Personal values that must never reach another student. */
    protected const PERSONAL = [
        'A' => ['number' => '260001', 'first' => 'Anabel', 'last' => 'Reyesta', 'email' => 'anabel.reyesta@tmcc.test',
            'dob' => '2005-02-02', 'address' => 'Blk 1 Lot 2 Cabezas Trece Martires', 'contact' => '09171110001'],
        'B' => ['number' => '260002', 'first' => 'Benedict', 'last' => 'Cruzado', 'email' => 'benedict.cruzado@tmcc.test',
            'dob' => '2004-09-09', 'address' => 'Blk 9 Lot 9 Sahud Ulan Tanza', 'contact' => '09172220002'],
    ];

    protected function seedTwoStudentRecords(): void
    {
        Storage::fake('local');
        $this->seedRoles();
        $this->registrar = $this->makeUser('staff');
        $this->admin = $this->makeUser('admin');
        $this->program = $this->makeProgram('BSTM', 'BS Tourism Management');
        $this->makeCurriculum($this->program, ['GEC4' => [1, 1], 'TPC1' => [1, 1], 'TPC2' => [1, 2]], ['TPC2' => ['TPC1']]);

        foreach (self::PERSONAL as $key => $p) {
            $user = $this->makeUser('student', $p['number']);
            $user->forceFill(['name' => "{$p['first']} {$p['last']}", 'email' => $p['email']])->save();

            $student = $this->makeStudent($this->program, [
                'student_number' => $p['number'], 'first_name' => $p['first'], 'last_name' => $p['last'],
                'email' => $p['email'], 'date_of_birth' => $p['dob'], 'address' => $p['address'],
                'contact_number' => $p['contact'], 'enrollment_date' => '2026-06-01',
            ], $user);

            foreach (['GEC4' => $key === 'A' ? 1.25 : 2.75, 'TPC1' => $key === 'A' ? 1.5 : 3.0] as $code => $value) {
                $enrollment = Enrollment::create([
                    'student_id' => $student->student_id, 'subject_id' => $this->subjects[$code]->id,
                    'academic_year' => '2026-2027', 'semester' => '1', 'year_level' => 1, 'status' => 'Passed',
                ]);
                $this->recordGrade($student, $code, '2026-2027', 1, $value, 'Passed')->update(['enrollment_id' => $enrollment->id]);
            }

            ArchiveRecord::create([
                'student_id' => $student->student_id, 'record_type' => 'Form 137', 'cabinet_no' => "CAB-{$key}",
                'shelf_no' => "SHELF-{$key}", 'folder_code' => "FOLDER-{$p['number']}", 'document_status' => 'Complete',
            ]);

            $path = "student-documents/{$student->student_id}/birth-certificate-{$key}.pdf";
            Storage::disk('local')->put($path, '%PDF-1.4 ' . $key);
            $document = StudentDocument::create([
                'student_id' => $student->student_id, 'uploaded_by' => $this->registrar->id, 'document_type' => 'PSA',
                'file_path' => $path, 'original_name' => "birth-certificate-{$key}.pdf", 'mime' => 'application/pdf', 'size' => 10,
            ]);

            $request = RecordRequest::create([
                'student_id' => $student->student_id, 'record_type' => 'transcript', 'purpose' => "Employment {$key}",
                'copies' => 1, 'status' => RecordRequest::STATUS_RELEASED, 'requested_at' => now()->subDays(5),
                'processed_at' => now()->subDays(4), 'appointment_at' => now()->subDays(2), 'released_at' => now()->subDay(),
            ]);

            $updatePath = "pending-profile-updates/proof-{$key}.pdf";
            Storage::disk('local')->put($updatePath, '%PDF-1.4 proof ' . $key);
            $update = PendingStudentUpdate::create([
                'student_id' => $student->student_id, 'submitted_by' => $user->id, 'status' => 'pending',
                'old_values' => ['address' => $p['address']], 'new_values' => ['address' => "New address {$key}"],
                'changed_fields' => ['address'], 'supporting_document_path' => $updatePath,
                'supporting_document_original_name' => "proof-{$key}.pdf", 'supporting_document_mime' => 'application/pdf',
                'supporting_document_size' => 16,
            ]);

            $this->people[$key] = compact('student', 'user', 'request', 'document', 'update');
        }
    }

    /** Fill {A}, {B}, {A.request}, {B.document}... in a URI with the fixture's ids. */
    protected function fillUri(string $uri): string
    {
        return preg_replace_callback('/\{([AB])(?:\.(request|document|update))?\}/', function ($m) {
            $person = $this->people[$m[1]];

            return (string) match ($m[2] ?? '') {
                'request'  => $person['request']->id,
                'document' => $person['document']->id,
                'update'   => $person['update']->id,
                default    => $person['student']->student_id,
            };
        }, $uri);
    }
}
