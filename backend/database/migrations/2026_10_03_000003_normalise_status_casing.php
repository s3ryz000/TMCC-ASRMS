<?php

use App\Support\AcademicStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Store every enrollment and grade status in the one vocabulary (#18).
 *
 * Only the casing changes ("archived" -> "Archived", "enrolled" ->
 * "Enrolled"). A value that is not a status in any casing ("completed",
 * "dropped", ...) aborts the migration, changing nothing, and names it, so
 * it can be decided by a person rather than guessed. NULL is left alone.
 *
 * down() does nothing: the original casing is not recorded, and every
 * canonical value means exactly what its lowercase spelling meant.
 */
return new class extends Migration
{
    private const TABLES = ['enrollments', 'grades'];

    public function up(): void
    {
        $changed = DB::transaction(function () {
            $before = $this->counts();

            $plan = [];
            $unknown = [];
            foreach (self::TABLES as $table) {
                foreach (DB::table($table)->whereNotNull('status')->distinct()->pluck('status') as $value) {
                    $canonical = collect(AcademicStatus::ALL)->first(fn (string $s) => strcasecmp($s, $value) === 0);
                    if ($canonical === null) {
                        $unknown[] = "{$table}.status \"{$value}\" (" . DB::table($table)->where('status', $value)->count() . ' rows)';
                    } elseif ($canonical !== $value) {
                        $plan[] = [$table, $value, $canonical];
                    }
                }
            }

            if ($unknown) {
                throw new RuntimeException('Status casing not normalised; nothing was changed. Not a known status: '
                    . implode('; ', $unknown) . '.');
            }

            $changed = [];
            foreach ($plan as [$table, $from, $to]) {
                $rows = DB::table($table)->where('status', $from)->update(['status' => $to]);
                $changed[] = "{$table}: \"{$from}\" -> \"{$to}\" ({$rows} rows)";
            }

            if ($this->counts() !== $before) {
                throw new RuntimeException('Status normalisation changed row counts; rolled back.');
            }

            return $changed;
        });

        if (! app()->runningUnitTests() && defined('STDOUT')) {
            fwrite(STDOUT, PHP_EOL . '  Status casing normalised: ' . ($changed ? implode('; ', $changed) : 'no rows needed it') . PHP_EOL);
        }
    }

    public function down(): void
    {
        // Intentionally empty: see the class comment.
    }

    private function counts(): array
    {
        return collect(self::TABLES)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }
};
