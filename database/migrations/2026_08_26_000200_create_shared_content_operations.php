<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->change();
            $table->foreignId('platform_account_id')->nullable()->after('actor_id')->constrained('accounts')->nullOnDelete();
        });

        Schema::create('company_courses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->timestamp('associated_at');
            $table->foreignId('associated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('removal_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'course_id']);
        });

        Schema::create('shared_content_propagations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // A reusable Module is stored in the existing lessons table. This row points at
            // the newly published immutable lesson/module version being propagated.
            $table->foreignId('lesson_id')->constrained()->restrictOnDelete();
            $table->foreignId('initiated_by_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('restart_in_progress')->default(false);
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('affected_count')->default(0);
            $table->unsignedInteger('not_started_count')->default(0);
            $table->unsignedInteger('in_progress_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('succeeded_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shared_content_propagation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('propagation_id')->constrained('shared_content_propagations')->restrictOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status')->default('pending')->index();
            $table->foreignId('source_course_version_id')->nullable()->constrained('course_versions')->nullOnDelete();
            $table->foreignId('result_course_version_id')->nullable()->constrained('course_versions')->nullOnDelete();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['propagation_id', 'course_id']);
            $table->unique('result_course_version_id');
        });

        Schema::table('course_versions', function (Blueprint $table): void {
            $table->foreignId('propagation_item_id')->nullable()->unique()
                ->constrained('shared_content_propagation_items')->nullOnDelete();
        });

        Schema::table('user_training_assignments', function (Blueprint $table): void {
            $table->dropUnique('assignments_requirement_cycle_unique');
            $table->unsignedInteger('replacement_generation')->default(0);
            $table->foreignId('publication_course_version_id')->nullable()->constrained('course_versions')->nullOnDelete();
            $table->foreignId('propagation_id')->nullable()->constrained('shared_content_propagations')->nullOnDelete();
            $table->unique('supersedes_assignment_id');
            $table->unique(
                ['user_id', 'training_requirement_id', 'cycle_number', 'replacement_generation'],
                'assignments_requirement_cycle_generation_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('user_training_assignments', function (Blueprint $table): void {
            $table->dropUnique('assignments_requirement_cycle_generation_unique');
            $table->dropUnique(['supersedes_assignment_id']);
            $table->dropConstrainedForeignId('publication_course_version_id');
            $table->dropConstrainedForeignId('propagation_id');
            $table->dropColumn('replacement_generation');
            $table->unique(['user_id', 'training_requirement_id', 'cycle_number'], 'assignments_requirement_cycle_unique');
        });
        Schema::table('course_versions', fn (Blueprint $table) => $table->dropConstrainedForeignId('propagation_item_id'));
        Schema::dropIfExists('shared_content_propagation_items');
        Schema::dropIfExists('shared_content_propagations');
        Schema::dropIfExists('company_courses');
        Schema::table('audit_logs', fn (Blueprint $table) => $table->dropConstrainedForeignId('platform_account_id'));
    }
};
