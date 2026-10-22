<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grade double approval (#107): encoded grades wait in a submission until a
 * registrar with "approve-grades" approves them, so a grades row only ever
 * holds approved values. Two new tables; no existing table is changed.
 *
 * `step` names the approval step a pending submission is waiting at. Today
 * there is one ("registrar"); faculty → coordinator → dean steps can be put in
 * front of it later (#109) without changing these tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->string('academic_year', 20);
            $table->string('semester', 20);
            $table->string('status', 20)->default('pending'); // pending | approved | returned | cancelled
            $table->string('step', 30)->default('registrar');
            $table->foreignId('submitted_by')->constrained('users');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->foreign('student_id')->references('student_id')->on('students');
            $table->index('status');
            $table->index(['student_id', 'academic_year', 'semester']);
        });

        Schema::create('grade_submission_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_submission_id')->constrained('grade_submissions')->cascadeOnDelete();
            $table->foreignId('grade_id')->constrained('grades');
            $table->decimal('grade_value', 4, 2)->nullable();
            $table->string('status', 20)->nullable();
            $table->string('remarks', 50)->nullable();
            $table->string('supporting_document_reference')->nullable();
            $table->json('old_value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_submission_items');
        Schema::dropIfExists('grade_submissions');
    }
};
