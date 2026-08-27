<?php

namespace Database\Seeders;

use App\Enums\CourseStatus;
use App\Enums\CourseVersionStatus;
use App\Enums\ModuleVersionStatus;
use App\Models\Company;
use App\Models\CompanyCourse;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\ModuleVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Video;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PermissionSeeder::class);

        $company = Company::query()->firstOrCreate(
            ['slug' => 'oceanix-demo'],
            ['name' => 'Oceanix Demo', 'status' => 'active'],
        );

        app(TenantContext::class)->set($company);

        $this->call(RoleSeeder::class);

        // Sample content stays out of production.
        if (app()->environment('local', 'testing')) {
            $this->call(DemoDataSeeder::class);
            $this->seedSharedLibrary($company);
        }
    }

    private function seedSharedLibrary(Company $demo): void
    {
        $moduleVersion = ModuleVersion::query()->updateOrCreate(
            ['code' => 'GLOBAL-MUSTER', 'version_number' => 1],
            ['company_id' => null, 'course_version_id' => null, 'is_shared' => true,
                'lineage_uuid' => '00000000-0000-4000-8000-000000000001',
                'status' => ModuleVersionStatus::Published, 'title' => 'Global emergency muster',
                'description' => 'Reusable platform-managed muster training.', 'published_at' => now()],
        );

        if ($moduleVersion->video()->doesntExist()) {
            Video::query()->create(['company_id' => null, 'lesson_id' => $moduleVersion->id, 'provider' => 'demo_placeholder', 'provider_asset_id' => 'demo-global-muster', 'duration_seconds' => 300, 'status' => 'ready']);
            $question = Question::query()->create(['company_id' => null, 'lesson_id' => $moduleVersion->id, 'prompt' => 'Where should you report after the muster alarm?', 'position' => 1]);
            QuestionOption::query()->create(['company_id' => null, 'question_id' => $question->id, 'text' => 'Assigned muster station', 'is_correct' => true, 'position' => 1]);
            QuestionOption::query()->create(['company_id' => null, 'question_id' => $question->id, 'text' => 'Personal cabin', 'is_correct' => false, 'position' => 2]);
        }
        $course = Course::query()->updateOrCreate(
            ['code' => 'GLOBAL-EMERGENCY'],
            ['company_id' => null, 'is_shared' => true, 'title' => 'Global emergency response', 'description' => 'Platform-managed emergency response training.', 'status' => CourseStatus::Active],
        );
        $courseVersion = CourseVersion::query()->firstOrCreate(
            ['course_id' => $course->id, 'version_number' => 1],
            ['status' => CourseVersionStatus::Published, 'title' => $course->title, 'description' => $course->description, 'completion_rule' => 'all_required_lessons', 'published_at' => now()],
        );
        CourseVersionModule::query()->firstOrCreate(
            ['course_version_id' => $courseVersion->id, 'module_version_id' => $moduleVersion->id],
            ['position' => 1, 'is_required' => true],
        );
        $course->update(['current_published_version_id' => $courseVersion->id]);

        $partner = Company::query()->firstOrCreate(['slug' => 'atlantic-partner'], ['name' => 'Atlantic Partner', 'status' => 'active']);
        foreach ([$demo, $partner] as $company) {
            app(TenantContext::class)->set($company);
            CompanyCourse::query()->updateOrCreate(
                ['course_id' => $course->id],
                ['associated_at' => now(), 'removed_at' => null, 'removal_reason' => null],
            );
        }
        app(TenantContext::class)->set($demo);
    }
}
