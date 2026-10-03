<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRole;
use App\Support\StudentNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Student numbers (#56): live availability for the New Student form. Registrar
 * only, and a taken number reveals only the holder's name and program.
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
}
