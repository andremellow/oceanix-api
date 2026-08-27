<?php

use App\Models\Company;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\Module;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates the shared content composition and operations schema', function (): void {
    expect(Schema::hasColumns('courses', ['company_id', 'is_shared']))->toBeTrue()
        ->and(Schema::hasColumns('lessons', ['company_id', 'is_shared', 'course_version_id', 'lineage_uuid', 'version_number', 'published_by_account_id']))->toBeTrue()
        ->and(Schema::hasTable('modules'))->toBeFalse()
        ->and(Schema::hasTable('module_versions'))->toBeFalse()
        ->and(Schema::hasTable('course_version_lessons'))->toBeTrue()
        ->and(Schema::hasTable('company_courses'))->toBeTrue()
        ->and(Schema::hasTable('shared_content_propagations'))->toBeTrue()
        ->and(Schema::hasTable('shared_content_propagation_items'))->toBeTrue()
        ->and(Schema::hasColumns('user_training_assignments', [
            'replacement_generation', 'publication_course_version_id', 'propagation_id',
        ]))->toBeTrue();
});

it('enforces paired content ownership fields in the database', function (): void {
    $insert = fn (array $attributes) => DB::table('lessons')->insert([
        'code' => fake()->unique()->lexify('????'),
        'title' => 'Invalid ownership',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);

    expect(fn () => $insert(['company_id' => Company::factory()->create()->id, 'is_shared' => true]))
        ->toThrow(QueryException::class)
        ->and(fn () => $insert(['company_id' => null, 'is_shared' => false]))
        ->toThrow(QueryException::class);
});

it('provides explicit shared and company-owned scopes', function (): void {
    $company = Company::factory()->create();
    $other = Company::factory()->create();

    Module::create(['company_id' => null, 'is_shared' => true, 'code' => 'GLOBAL', 'title' => 'Global']);
    Module::create(['company_id' => $company->id, 'is_shared' => false, 'code' => 'LOCAL', 'title' => 'Local']);
    Module::create(['company_id' => $other->id, 'is_shared' => false, 'code' => 'OTHER', 'title' => 'Other']);

    expect(Module::shared()->pluck('code')->all())->toBe(['GLOBAL'])
        ->and(Module::companyOwned($company)->pluck('code')->all())->toBe(['LOCAL']);
});

it('keeps the legacy lesson table while storing module identity and version metadata on it', function (): void {
    expect(Schema::hasColumns('lessons', ['course_version_id', 'lineage_uuid', 'version_number']))->toBeTrue()
        ->and(Schema::hasColumn('lessons', 'module_version_id'))->toBeFalse();
});

it('preserves lesson identifiers while creating the course composition snapshot', function (): void {
    $course = Course::factory()->create();
    $version = CourseVersion::factory()->published()->create(['course_id' => $course->id]);
    $lesson = Lesson::factory()->create(['course_version_id' => $version->id, 'position' => 3]);
    expect(DB::table('lessons')->where('id', $lesson->id)->value('id'))->toBe($lesson->id)
        ->and(DB::table('lessons')->where('id', $lesson->id)->value('lineage_uuid'))->not->toBeNull()
        ->and(DB::table('course_version_lessons')->where('course_version_id', $version->id)->where('lesson_id', $lesson->id)->value('position'))->toBe(3);
});
