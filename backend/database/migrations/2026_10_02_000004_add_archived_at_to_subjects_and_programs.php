<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subjects and programs that are still referenced cannot be deleted (#15);
 * archiving is how the registrar retires them instead. Nothing filters on it
 * yet: an archived row keeps working everywhere until the Curriculum Builder
 * starts hiding it from new curricula and enrollments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
        });
        Schema::table('programs', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
        Schema::table('programs', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
