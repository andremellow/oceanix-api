<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Course -> CourseVersion -> Lesson -> Video/Questions.
 *
 * `courses` is the permanent identity; `course_versions` is the auditable edition. Once a
 * version is published it becomes immutable — any change to a video, question, option,
 * correct answer, threshold or lesson order requires a new version, because assignments and
 * certificates freeze a specific `course_version_id`. See docs/product-spec.md §6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('draft')->index();
            $table->unsignedBigInteger('current_published_version_id')->nullable();
            $table->timestamps();
        });

        Schema::create('course_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status')->default('draft')->index();
            $table->string('title');
            $table->text('description')->nullable();
            // How the course as a whole is considered complete. The MVD ships
            // `all_required_lessons`; the column keeps room for future rules.
            $table->string('completion_rule')->default('all_required_lessons');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['course_id', 'version_number']);
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->foreign('current_published_version_id')
                ->references('id')->on('course_versions')->nullOnDelete();
        });

        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_version_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type')->default('video');
            $table->unsignedInteger('position')->default(1);
            $table->boolean('is_required')->default(true);
            // Percentage of the video that must be watched before the assessment unlocks.
            $table->unsignedTinyInteger('minimum_watch_percentage')->default(90);
            // Percentage of question weight required to pass the lesson assessment.
            $table->unsignedTinyInteger('passing_score')->default(70);
            $table->timestamps();

            $table->index(['course_version_id', 'position']);
        });

        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('cloudflare_stream');
            // Stable provider identifiers only — never a permanent public URL, since
            // authorization is always re-derived server-side. See docs/product-spec.md §12.
            $table->string('provider_asset_id');
            $table->string('provider_playback_id')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('status')->default('uploading')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_asset_id']);
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('single_choice');
            $table->text('prompt');
            $table->unsignedInteger('position')->default(1);
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->unsignedInteger('weight')->default(1);
            $table->timestamps();

            $table->index(['lesson_id', 'position']);
        });

        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('position')->default(1);
            $table->timestamps();

            $table->index(['question_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropForeign(['current_published_version_id']);
        });

        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('videos');
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('course_versions');
        Schema::dropIfExists('courses');
    }
};
