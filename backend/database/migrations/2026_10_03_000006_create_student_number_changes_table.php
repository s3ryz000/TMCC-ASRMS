<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every student number change (#56): the conversion to the YY+4 format and
 * each registrar correction, with the login username that changed with it.
 * Like subject_code_changes (#16), it lets the conversion be undone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_number_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students', 'student_id')->cascadeOnDelete();
            $table->string('old_number', 20);
            $table->string('new_number', 20);
            $table->string('old_username')->nullable();
            $table->string('new_username')->nullable();
            $table->string('source', 20); // 'migration' or 'registrar'
            $table->string('reason', 255)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_number_changes');
    }
};
