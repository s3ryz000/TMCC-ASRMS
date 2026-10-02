<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The CHED subject code prefixes (TPC, GEC, ...) and what they stand for, so
 * the catalogue can be grouped and filtered by them (#69). The rows are part
 * of the migration so `php artisan migrate` alone fills the table everywhere.
 * Subjects are matched to a prefix by their code; nothing references this
 * table by id.
 */
return new class extends Migration
{
    private const PREFIXES = [
        'GEC'       => 'General Education Core',
        'GEE'       => 'General Education Elective',
        'GE ELECT'  => 'General Education Elective (legacy codes)',
        'NSTP'      => 'National Service Training Program',
        'PATHFIT'   => 'Physical Activity Towards Health and Fitness',
        'THC'       => 'Tourism and Hospitality Core',
        'TPC'       => 'Tourism Professional Core',
        'TMPE'      => 'Tourism Management Professional Elective',
        'HPC'       => 'Hospitality Professional Core',
        'HMPE'      => 'Hospitality Management Professional Elective',
        'BME'       => 'Business Management Education',
        'PRACTICUM' => 'Practicum / On-the-Job Training',
        'ENT'       => 'Entrepreneurship',
        'ACCTG'     => 'Accounting',
        'MGT'       => 'Management',
        'MKG'       => 'Marketing',
        'HRM'       => 'Human Resource Management',
        'OM'        => 'Operations Management',
        'MIS'       => 'Management Information System',
        'LAW'       => 'Business Law and Taxation',
        'STRAMA'    => 'Strategic Management',
        'HUM'       => 'Humanities',
        'MDA'       => 'Multimedia Development Application',
        'MOE'       => 'Microsoft Office / Productivity Tools',
        'ELT'       => 'Entrepreneurship Elective',
    ];

    public function up(): void
    {
        Schema::create('subject_code_prefixes', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 20)->unique();
            $table->string('full_name', 150);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('subject_code_prefixes')->insert(
            collect(self::PREFIXES)
                ->map(fn ($fullName, $prefix) => [
                    'prefix' => $prefix, 'full_name' => $fullName, 'active' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ])
                ->values()
                ->all()
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_code_prefixes');
    }
};
