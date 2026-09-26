<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Appointments were written as UTC wall-clock time, but every timestamp is
 * read back in the application timezone, so a 2:00 PM booking displayed as
 * 6:00 AM. The approve endpoint now stores application-timezone time like
 * every other column; this converts the rows written before that change.
 *
 * Every existing value came from the approve endpoint, which only ever wrote
 * UTC, so converting all of them is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->shift('UTC', config('app.timezone'));
    }

    public function down(): void
    {
        $this->shift(config('app.timezone'), 'UTC');
    }

    private function shift(string $from, string $to): void
    {
        DB::table('record_requests')
            ->whereNotNull('appointment_at')
            ->orderBy('id')
            ->each(function ($row) use ($from, $to) {
                DB::table('record_requests')->where('id', $row->id)->update([
                    'appointment_at' => Carbon::parse($row->appointment_at, $from)
                        ->setTimezone($to)
                        ->toDateTimeString(),
                ]);
            });
    }
};
