<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Student numbers to the YY+4 format (#56, decided 29 Sep):
 *
 *   TMCC-2026-0003 -> 260003, TMCC-2025-001 -> 250001, STU-2027-001 -> 270001
 *
 * The year comes from the number itself, and the number's digits are padded
 * to 4. The matching login username (users.username = the old number) changes
 * with it. Numbers already in the new format are left alone. Grades,
 * enrollments, requests, documents and archive records point at student_id,
 * so they don't change.
 *
 * It refuses to run, changing nothing, if a number matches neither old
 * pattern or a converted number would collide, and lists them. Every table's
 * row count (except the change log) and PRAGMA foreign_key_check are compared
 * before and after. Each change is recorded in student_number_changes, which
 * down() replays backwards.
 */
return new class extends Migration
{
    private const PATTERNS = [
        '/^TMCC-(\d{2})(\d{2})-(\d{3,4})$/',
        '/^STU-(\d{2})(\d{2})-(\d{3})$/',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $before = $this->snapshot();
            $plan = $this->plan();

            foreach ($plan as $change) {
                DB::table('students')->where('student_id', $change['student_id'])->update(['student_number' => $change['new_number']]);
                if ($change['user_id'] !== null) {
                    DB::table('users')->where('id', $change['user_id'])->update(['username' => $change['new_number']]);
                }
                DB::table('student_number_changes')->insert([
                    'student_id'   => $change['student_id'],
                    'old_number'   => $change['old_number'],
                    'new_number'   => $change['new_number'],
                    'old_username' => $change['user_id'] !== null ? $change['old_number'] : null,
                    'new_username' => $change['user_id'] !== null ? $change['new_number'] : null,
                    'source'       => 'migration',
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
                echo "  {$change['old_number']} -> {$change['new_number']}" . ($change['user_id'] !== null ? ' (and username)' : '') . "\n";
            }

            $this->assertUnchanged($before);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $before = $this->snapshot();
            $changes = DB::table('student_number_changes')->where('source', 'migration')->orderByDesc('id')->get();

            foreach ($changes as $change) {
                $student = DB::table('students')->where('student_id', $change->student_id)->first();
                if (! $student || $student->student_number !== $change->new_number) {
                    throw new RuntimeException("Student {$change->student_id} no longer has {$change->new_number}; nothing was restored. "
                        . 'Undo later number changes first.');
                }
                if (DB::table('students')->where('student_number', $change->old_number)->where('student_id', '!=', $change->student_id)->exists()) {
                    throw new RuntimeException("{$change->old_number} is used by another student now; nothing was restored.");
                }

                DB::table('students')->where('student_id', $change->student_id)->update(['student_number' => $change->old_number]);
                if ($change->old_username !== null) {
                    DB::table('users')->where('id', $student->user_id)->where('username', $change->new_username)->update(['username' => $change->old_username]);
                }
                DB::table('student_number_changes')->where('id', $change->id)->delete();
            }

            $this->assertUnchanged($before);
        });
    }

    /**
     * Every conversion, or an exception naming each number that can't be
     * converted safely.
     *
     * @return list<array{student_id: int, user_id: ?int, old_number: string, new_number: string}>
     */
    private function plan(): array
    {
        $students = DB::table('students')->get(['student_id', 'user_id', 'student_number']);
        $plan = [];
        $unknown = [];

        foreach ($students as $student) {
            $number = (string) $student->student_number;
            if (preg_match('/^\d{6}$/', $number)) {
                continue; // already converted
            }

            $new = null;
            foreach (self::PATTERNS as $pattern) {
                if (preg_match($pattern, $number, $m)) {
                    $new = $m[2] . str_pad($m[3], 4, '0', STR_PAD_LEFT);
                    break;
                }
            }
            if ($new === null) {
                $unknown[] = "{$number} (student {$student->student_id})";
                continue;
            }

            // The login changes only where it is the student number.
            $userId = $student->user_id !== null
                && DB::table('users')->where('id', $student->user_id)->value('username') === $number
                ? (int) $student->user_id : null;

            $plan[] = ['student_id' => (int) $student->student_id, 'user_id' => $userId, 'old_number' => $number, 'new_number' => $new];
        }

        $collisions = [];
        $converting = collect($plan)->pluck('student_id')->all();
        foreach (collect($plan)->groupBy('new_number') as $new => $group) {
            if ($group->count() > 1) {
                $collisions[] = "{$new} <- " . $group->pluck('old_number')->join(', ');
                continue;
            }
            $change = $group->first();
            $takenByStudent = DB::table('students')->where('student_number', $new)->whereNotIn('student_id', $converting)->exists();
            $takenByLogin = DB::table('users')->where('username', $new)
                ->when($change['user_id'], fn ($q, $id) => $q->where('id', '!=', $id))
                ->exists();
            if ($takenByStudent || $takenByLogin) {
                $collisions[] = "{$new} <- {$change['old_number']} ({$new} is already in use)";
            }
        }

        if ($unknown || $collisions) {
            throw new RuntimeException('Student numbers not converted; nothing was changed. '
                . ($unknown ? 'Not a known old format: ' . implode('; ', $unknown) . '. ' : '')
                . ($collisions ? 'Would collide: ' . implode('; ', $collisions) . '.' : ''));
        }

        return $plan;
    }

    private function assertUnchanged(array $before): void
    {
        $after = $this->snapshot();
        if ($after !== $before) {
            throw new RuntimeException('Converting student numbers changed row counts or broke a reference; nothing was changed. Before: '
                . json_encode($before) . ' After: ' . json_encode($after));
        }
    }

    /** Row counts of every table except the change log, plus foreign-key violations on SQLite. */
    private function snapshot(): array
    {
        $counts = collect(Schema::getTableListing(Schema::getCurrentSchemaName(), false))
            ->reject(fn (string $table) => $table === 'student_number_changes')
            ->sort()
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
            ->all();

        if (DB::getDriverName() === 'sqlite') {
            $counts['__foreign_key_violations'] = count(DB::select('PRAGMA foreign_key_check'));
        }

        return $counts;
    }
};
