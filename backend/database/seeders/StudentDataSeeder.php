<?php

namespace Database\Seeders;

use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\AcademicStatus;
use Illuminate\Database\Seeder;

class StudentDataSeeder extends Seeder
{
    public function run(): void
    {
        $program = Program::firstOrCreate(
            ['code' => 'BSIT'],
            ['name' => 'Bachelor of Science in Information Technology', 'description' => 'BSIT Program']
        );

        $subjects = [
            ['code' => 'CC101', 'title' => 'Introduction to Computing', 'units' => 3],
            ['code' => 'CC102', 'title' => 'Computer Programming 1', 'units' => 3],
            ['code' => 'CC103', 'title' => 'Data Structures', 'units' => 3],
            ['code' => 'CC201', 'title' => 'Database Management', 'units' => 3],
            ['code' => 'CC202', 'title' => 'Web Development', 'units' => 3],
        ];
        $subjectModels = [];
        foreach ($subjects as $s) {
            $subjectModels[$s['code']] = Subject::firstOrCreate(['code' => $s['code']], array_merge($s, ['description' => null]));
        }

        $semOrder = ['1st' => 0, '2nd' => 1];
        foreach ([1, 2] as $yearLevel) {
            foreach (['1st', '2nd'] as $sem) {
                $startIdx = ($yearLevel - 1) * 2 + $semOrder[$sem];
                $subs = array_slice($subjects, $startIdx, 2);
                foreach ($subs as $s) {
                    $sub = $subjectModels[$s['code']];
                    Curriculum::firstOrCreate(
                        [
                            'program_id' => $program->id,
                            'subject_id' => $sub->id,
                            'year_level' => $yearLevel,
                            'semester' => $sem,
                        ],
                        []
                    );
                }
            }
        }

        $adminUser = User::where('username', 'admin')->first();
        $staffUser = User::where('username', 'staff')->first();
        if ($adminUser && ! $adminUser->staff) {
            Staff::create([
                'user_id' => $adminUser->id,
                'first_name' => 'Admin',
                'last_name' => 'User',
                'role' => 'admin',
                'email' => $adminUser->email,
            ]);
        }
        if ($staffUser && ! $staffUser->staff) {
            Staff::create([
                'user_id' => $staffUser->id,
                'first_name' => 'Staff',
                'last_name' => 'Registrar',
                'role' => 'staff',
                'email' => $staffUser->email,
            ]);
        }

        $studentUser = User::where('username', 'student')->first();
        if (! $studentUser) {
            return;
        }

        $student = Student::where('user_id', $studentUser->id)->first();
        if (! $student) {
            $student = Student::create([
                'user_id' => $studentUser->id,
                'program_id' => $program->id,
                'student_number' => 'TMCC-2025-001',
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'date_of_birth' => '2002-05-15',
                'email' => $studentUser->email,
                'contact_number' => '09171234567',
                'address' => 'Trece Martires City',
                'enrollment_date' => '2024-08-01',
                'graduation_date' => null,
                'GPA' => 1.75,
            ]);
        }

        $ay = '2025-2026';
        $sem = '2nd';
        foreach (['CC101', 'CC102', 'CC201'] as $code) {
            $sub = $subjectModels[$code] ?? null;
            if (! $sub) {
                continue;
            }
            // Every grade belongs to an enrollment (#19), with statuses from
            // the one vocabulary (#18).
            $enrollment = Enrollment::firstOrCreate(
                [
                    'student_id' => $student->student_id,
                    'subject_id' => $sub->id,
                    'academic_year' => $ay,
                    'semester' => $sem,
                ],
                ['status' => AcademicStatus::PASSED]
            );
            Grade::firstOrCreate(
                [
                    'student_id' => $student->student_id,
                    'subject_id' => $sub->id,
                    'academic_year' => $ay,
                    'semester' => $sem,
                ],
                ['grade_value' => 1.75, 'remarks' => 'Passed', 'status' => AcademicStatus::PASSED, 'enrollment_id' => $enrollment->id]
            );
        }

        $this->command->info('Student data (program, subjects, curriculum, staff links, student record, enrollments, grades) seeded.');
    }
}
