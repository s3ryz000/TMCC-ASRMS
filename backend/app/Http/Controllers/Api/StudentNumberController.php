<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Models\Student;
use App\Models\SystemLog;
use App\Support\StudentNumber;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Student numbers (#56): live availability for the New Student form, Change
 * Student Number and the ID check. Registrar only; a taken number reveals
 * only the holder's name and program on the live check.
 */
class StudentNumberController extends Controller
{
    use AuthorizesRole;

    /** GET /staff/student-numbers/check?number=260004 */
    public function check(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }

        $validated = $request->validate(
            ['number' => ['required', 'string', 'regex:' . StudentNumber::PATTERN]],
            ['number.regex' => StudentNumber::FORMAT_MESSAGE],
        );
        $number = $validated['number'];
        $available = StudentNumber::isAvailable($number);

        return response()->json(array_filter([
            'number'         => $number,
            'available'      => $available,
            'next_available' => StudentNumber::nextAvailable(substr($number, 0, 2)),
            'conflict'       => $available ? null : StudentNumber::conflict($number, false),
        ], fn ($value) => $value !== null));
    }

    /**
     * PATCH /staff/students/{id}/student-number {student_number, reason}
     *
     * Corrects a student number, even when two records were both wrong: one
     * after the other, since a freed number is available at once (a swap takes
     * two steps). The login username follows in the same transaction, the
     * student's tokens are revoked so the old login stops working, and the
     * change is audited with its reason.
     */
    public function change(Request $request, int $id): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }

        $student = Student::with('user')->find($id);
        if (! $student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        if (is_string($number = $request->input('student_number'))) {
            $request->merge(['student_number' => preg_replace('/\s+/', '', $number)]);
        }
        $prefix = StudentNumber::yearPrefix($student->getRawOriginal('enrollment_date'));
        $validated = $request->validate([
            'student_number' => ['required', 'string', 'regex:' . StudentNumber::PATTERN, function (string $attribute, mixed $value, Closure $fail) use ($student, $prefix) {
                if ($value === $student->student_number) {
                    $fail("{$value} is already this student's number.");
                } elseif ($prefix !== null && substr($value, 0, 2) !== $prefix) {
                    $fail(StudentNumber::wrongYearMessage($prefix));
                }
            }],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'student_number.regex' => StudentNumber::FORMAT_MESSAGE,
            'reason.required'      => 'Give a reason for the change; it is kept in the audit log.',
        ]);
        $new = $validated['student_number'];
        $reason = trim($validated['reason']);

        if (! StudentNumber::isAvailable($new, $student)) {
            return response()->json(StudentNumber::takenResponse($new, $student), 422);
        }

        $old = $student->student_number;
        $account = $student->user;
        // The login follows the number where it is the number.
        $followsNumber = $account && $account->username === $old;
        $actor = $request->user();

        try {
            DB::transaction(function () use ($student, $account, $followsNumber, $old, $new, $reason, $actor) {
                $student->forceFill(['student_number' => $new])->save();
                if ($followsNumber) {
                    $account->forceFill(['username' => $new])->save();
                }
                // Signed-in sessions end: the old number no longer logs in.
                $account?->tokens()->delete();

                DB::table('student_number_changes')->insert([
                    'student_id'   => $student->student_id,
                    'old_number'   => $old,
                    'new_number'   => $new,
                    'old_username' => $followsNumber ? $old : null,
                    'new_username' => $followsNumber ? $new : null,
                    'source'       => 'registrar',
                    'reason'       => $reason,
                    'changed_by'   => $actor->id,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);

                SystemLog::create([
                    'action'  => "Student number changed: {$old} → {$new} ({$student->first_name} {$student->last_name}). Reason: {$reason}",
                    'user_id' => $actor->id,
                    'role'    => $this->userRole($actor),
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Taken between the check and the save.
            return response()->json(StudentNumber::takenResponse($new, $student), 422);
        }

        return response()->json([
            'message'         => "Student number changed from {$old} to {$new}.",
            'student_number'  => $new,
            'previous_number' => $old,
            'username'        => $account?->fresh()->username,
        ]);
    }

    /**
     * GET /staff/student-numbers/mismatches: the ID check (#56). Students whose
     * number doesn't start with their enrollment year, or isn't in the YY+4
     * format at all.
     */
    public function mismatches(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) {
            return $err;
        }
        if ($err = $this->requireRoles($request->user(), ['staff'])) {
            return $err;
        }

        $rows = Student::with('program:id,code')
            ->orderBy('student_number')
            ->get(['student_id', 'student_number', 'first_name', 'last_name', 'program_id', 'enrollment_date'])
            ->map(function (Student $student) {
                $enrolled = $student->getRawOriginal('enrollment_date');
                $expected = StudentNumber::yearPrefix($enrolled);
                $number = (string) $student->student_number;

                $problem = match (true) {
                    ! StudentNumber::isValid($number) => 'Not in the YY+4 format',
                    $expected === null                => 'No enrollment date',
                    substr($number, 0, 2) !== $expected => "Starts with " . substr($number, 0, 2) . ", enrolled in 20{$expected}",
                    default                           => null,
                };

                return $problem === null ? null : [
                    'student_id'      => $student->student_id,
                    'student_number'  => $number,
                    'name'            => trim("{$student->first_name} {$student->last_name}"),
                    'program'         => $student->program?->code,
                    'enrollment_date' => $enrolled ? substr($enrolled, 0, 10) : null,
                    'expected_prefix' => $expected,
                    'problem'         => $problem,
                ];
            })
            ->filter()
            ->values();

        return response()->json(['mismatches' => $rows]);
    }
}
