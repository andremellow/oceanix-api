<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A training requirement declares who must hold a training and how often. It is a rule,
 * not an individual obligation — the obligation is materialized in
 * `user_training_assignments`. Because recurrence can differ per department or function,
 * each distinct periodicity is its own requirement even when it points at the same course.
 * See docs/product-spec.md §8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('draft')->index();
            $table->string('frequency_type')->default('once');
            $table->unsignedInteger('frequency_value')->nullable();
            $table->string('renewal_basis')->default('from_completion');
            // How early the next occurrence becomes available before it is due.
            $table->unsignedInteger('assignment_lead_days')->default(0);
            $table->unsignedInteger('due_days_after_assignment')->default(30);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('training_requirement_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_requirement_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type');
            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('job_function_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->index(['training_requirement_id', 'scope_type'], 'requirement_targets_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_requirement_targets');
        Schema::dropIfExists('training_requirements');
    }
};
