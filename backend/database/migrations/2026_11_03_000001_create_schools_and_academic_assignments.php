<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (TMCC letter, 27 Oct 2026, 1A–1C): programs belong to schools,
 * each school has a dean, programs may have coordinators, and faculty belong
 * to a school. Every new column is nullable, so existing data stays valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            // One dean per school, and a dean leads one school.
            $table->foreignId('dean_user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->after('description')->constrained('schools')->nullOnDelete();
        });

        Schema::create('program_coordinators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['program_id', 'user_id']);
        });

        Schema::create('faculty_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('employee_no', 30)->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faculty_profiles');
        Schema::dropIfExists('program_coordinators');
        Schema::table('programs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_id');
        });
        Schema::dropIfExists('schools');
    }
};
