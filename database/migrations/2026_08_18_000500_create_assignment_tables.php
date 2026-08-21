<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The materialized, historical obligation for one person to complete one course version,
 * plus the attempt trail underneath it. Attempts are never overwritten: a new run creates
 * new rows so failures and rewatches stay auditable. See docs/product-spec.md §7 and §9.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_training_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            // Frozen at materialization: republishing a course never rewrites history.
            $table->foreignId('course_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('training_requirement_id')->nullable()->constrained()->nullOnDelete();
            $table->string('origin_type')->default('manual')->index();
            $table->string('origin_id')->nullable();
            // Groups the occurrences of one recurring obligation without generating the
            // whole future series up front.
            $table->string('series_key')->nullable()->index();
            $table->unsignedInteger('cycle_number')->default(1);
            $table->timestamp('assigned_at');
            $table->timestamp('available_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status')->default('pending')->index();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('supersedes_assignment_id')->nullable()
                ->constrained('user_training_assignments')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Idempotency key for the materialization engine: one occurrence per
            // user + requirement + cycle, so re-running the job creates nothing new.
            $table->unique(
                ['user_id', 'training_requirement_id', 'cycle_number'],
                'assignments_requirement_cycle_unique'
            );
            $table->index(['user_id', 'status']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('course_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignment_id')->constrained('user_training_assignments')->cascadeOnDelete();
            $table->foreignId('course_version_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('attempt_number')->default(1);
            $table->string('status')->default('in_progress')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->timestamps();

            $table->unique(['assignment_id', 'attempt_number']);
        });

        Schema::create('lesson_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('attempt_number')->default(1);
            $table->string('status')->default('in_progress')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->timestamps();

            $table->unique(['course_attempt_id', 'lesson_id', 'attempt_number'], 'lesson_attempts_unique');
        });

        Schema::create('question_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('attempt_number')->default(1);
            $table->json('selected_option_ids');
            $table->boolean('is_correct')->default(false);
            $table->timestamp('answered_at');
            $table->timestamps();

            $table->unique(['lesson_attempt_id', 'question_id', 'attempt_number'], 'question_attempts_unique');
        });

        // Derived read model for fast progress rendering. It never replaces the
        // append-only compliance events, which remain the evidence of record.
        Schema::create('lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignment_id')->constrained('user_training_assignments')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->unsignedInteger('last_position_seconds')->default(0);
            $table->unsignedInteger('watched_seconds')->default(0);
            $table->unsignedTinyInteger('percentage_watched')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['assignment_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_progress');
        Schema::dropIfExists('question_attempts');
        Schema::dropIfExists('lesson_attempts');
        Schema::dropIfExists('course_attempts');
        Schema::dropIfExists('user_training_assignments');
    }
};
