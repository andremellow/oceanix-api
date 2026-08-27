<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Adds sharing and reusable-content versioning to the existing course graph.
 *
 * The physical `lessons` table is intentionally retained. In the product a lesson is called a
 * Module, but it is the same content unit; there is no Module -> Lesson container relationship.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'code']);
            $table->foreignId('company_id')->nullable()->change();
            $table->boolean('is_shared')->default(false)->after('company_id');
        });

        Schema::table('course_versions', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->change();
            $table->string('publication_kind')->default('manual');
            $table->foreignId('source_course_version_id')->nullable()->constrained('course_versions')->nullOnDelete();
            $table->foreignId('published_by_account_id')->nullable()->constrained('accounts')->nullOnDelete();
        });

        Schema::table('lessons', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->change();
            $table->foreignId('course_version_id')->nullable()->change();
            $table->boolean('is_shared')->default(false)->after('company_id');
            $table->string('code')->nullable()->after('is_shared');
            $table->uuid('lineage_uuid')->nullable()->after('code');
            $table->unsignedInteger('version_number')->default(1)->after('lineage_uuid');
            $table->string('status')->default('draft')->index()->after('version_number');
            $table->timestamp('published_at')->nullable()->after('status');
            $table->foreignId('published_by_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('source_lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
        });

        Schema::create('course_version_lessons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->boolean('is_required')->default(true);
            $table->timestamps();
            $table->unique(['course_version_id', 'position']);
            $table->unique(['course_version_id', 'lesson_id']);
        });

        foreach (['videos', 'questions', 'question_options'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('company_id')->nullable()->change();
            });
        }

        $this->backfillExistingLessons();
        $this->addIndexesAndOwnershipConstraints();
    }

    private function backfillExistingLessons(): void
    {
        $lineages = [];
        $previousLessonIds = [];

        DB::table('lessons')
            ->join('course_versions', 'course_versions.id', '=', 'lessons.course_version_id')
            ->select([
                'lessons.id', 'lessons.course_version_id', 'lessons.position', 'lessons.is_required',
                'course_versions.course_id', 'course_versions.version_number as course_version_number',
                'course_versions.status as course_version_status', 'course_versions.published_at',
            ])
            ->orderBy('course_versions.course_id')
            ->orderBy('course_versions.version_number')
            ->orderBy('lessons.position')
            ->each(function (object $lesson) use (&$lineages, &$previousLessonIds): void {
                $status = match ($lesson->course_version_status) {
                    'published' => 'published',
                    'retired' => 'retired',
                    default => 'draft',
                };
                $lineageKey = $lesson->course_id.':'.$lesson->position;
                $lineage = $lineages[$lineageKey] ??= (string) Str::uuid();

                DB::table('lessons')->where('id', $lesson->id)->update([
                    'code' => 'course-'.$lesson->course_id.'-module-'.$lesson->position,
                    'lineage_uuid' => $lineage,
                    'version_number' => $lesson->course_version_number,
                    'status' => $status,
                    'published_at' => in_array($status, ['published', 'retired'], true) ? $lesson->published_at : null,
                    'source_lesson_id' => $previousLessonIds[$lineageKey] ?? null,
                ]);
                $previousLessonIds[$lineageKey] = $lesson->id;

                DB::table('course_version_lessons')->insert([
                    'course_version_id' => $lesson->course_version_id,
                    'lesson_id' => $lesson->id,
                    'position' => $lesson->position,
                    'is_required' => $lesson->is_required,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        Schema::table('lessons', function (Blueprint $table): void {
            $table->string('code')->nullable(false)->change();
            $table->uuid('lineage_uuid')->nullable(false)->change();
        });
    }

    private function addIndexesAndOwnershipConstraints(): void
    {
        $true = DB::getDriverName() === 'pgsql' ? 'is_shared' : 'is_shared = 1';
        $false = DB::getDriverName() === 'pgsql' ? 'NOT is_shared' : 'is_shared = 0';

        DB::statement("CREATE UNIQUE INDEX courses_company_code_unique ON courses (company_id, code) WHERE {$false} AND company_id IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX courses_shared_code_unique ON courses (code) WHERE {$true} AND company_id IS NULL");
        DB::statement("CREATE UNIQUE INDEX lessons_company_code_version_unique ON lessons (company_id, code, version_number) WHERE {$false} AND company_id IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX lessons_shared_code_version_unique ON lessons (code, version_number) WHERE {$true} AND company_id IS NULL");
        $this->createIndex('lessons_lineage_version_unique', 'lessons', ['lineage_uuid', 'version_number'], true);

        if (DB::getDriverName() === 'sqlite') {
            foreach (['courses', 'lessons'] as $table) {
                DB::statement("CREATE TRIGGER {$table}_ownership_insert BEFORE INSERT ON {$table} WHEN NOT ((NEW.is_shared = 1 AND NEW.company_id IS NULL) OR (NEW.is_shared = 0 AND NEW.company_id IS NOT NULL)) BEGIN SELECT RAISE(ABORT, 'invalid content ownership'); END");
                DB::statement("CREATE TRIGGER {$table}_ownership_update BEFORE UPDATE OF company_id, is_shared ON {$table} WHEN NOT ((NEW.is_shared = 1 AND NEW.company_id IS NULL) OR (NEW.is_shared = 0 AND NEW.company_id IS NOT NULL)) BEGIN SELECT RAISE(ABORT, 'invalid content ownership'); END");
            }

            return;
        }

        DB::statement('ALTER TABLE courses ADD CONSTRAINT courses_ownership_check CHECK ((is_shared AND company_id IS NULL) OR (NOT is_shared AND company_id IS NOT NULL))');
        DB::statement('ALTER TABLE lessons ADD CONSTRAINT lessons_ownership_check CHECK ((is_shared AND company_id IS NULL) OR (NOT is_shared AND company_id IS NOT NULL))');
    }

    private function createIndex(string $name, string $table, array $columns, bool $unique = false): void
    {
        $keyword = $unique ? 'UNIQUE ' : '';
        DB::statement("CREATE {$keyword}INDEX {$name} ON {$table} (".implode(', ', $columns).')');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['courses', 'lessons'] as $table) {
                DB::statement("DROP TRIGGER IF EXISTS {$table}_ownership_insert");
                DB::statement("DROP TRIGGER IF EXISTS {$table}_ownership_update");
            }
        }

        Schema::dropIfExists('course_version_lessons');
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('published_by_account_id');
            $table->dropConstrainedForeignId('source_lesson_id');
            $table->dropColumn(['is_shared', 'code', 'lineage_uuid', 'version_number', 'status', 'published_at']);
        });
        Schema::table('course_versions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_course_version_id');
            $table->dropConstrainedForeignId('published_by_account_id');
            $table->dropColumn('publication_kind');
        });
        Schema::table('courses', fn (Blueprint $table) => $table->dropColumn('is_shared'));
    }
};
