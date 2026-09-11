<?php

namespace Tests\Support\CourseEditor;

use App\Enums\Permission;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\Lesson;
use App\Models\Module;
use App\Models\ModuleVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final readonly class EditorFixture
{
    /**
     * @param  array<string, int>  $session
     * @param  list<int>  $recordIds
     */
    private function __construct(
        public EditorContextCase $context,
        public Course|Module $root,
        public ?User $user,
        public array $session,
        public array $recordIds,
        public string $token,
    ) {}

    public static function create(EditorContextCase $context): self
    {
        $token = 'editor-'.Str::lower((string) Str::ulid());

        Http::preventStrayRequests();
        Http::fake([
            'api.cloudflare.com/client/v4/accounts/*/stream/direct_upload' => Http::response(['success' => true, 'result' => [
                'uid' => $token.'-asset',
                'uploadURL' => 'https://upload.example/'.$token,
            ]]),
            'api.cloudflare.com/*' => Http::response(['success' => true, 'result' => []]),
        ]);

        return match ($context->name) {
            'company-course' => self::companyCourse($context, $token),
            'shared-course' => self::sharedCourse($context, $token),
            'shared-module' => self::sharedModule($context, $token),
        };
    }

    /** @return array<string, int|string> */
    public function routeParameters(): array
    {
        return match ($this->context->name) {
            'company-course' => ['company' => $this->root->company, 'course' => $this->root],
            'shared-course' => ['course' => $this->root],
            'shared-module' => ['module' => $this->root],
        };
    }

    public function url(): string
    {
        return route($this->context->route, $this->routeParameters(), false);
    }

    private static function companyCourse(EditorContextCase $context, string $token): self
    {
        $user = \userWithPermissions([Permission::CoursesUpdate]);
        $course = Course::factory()->draft()->create(['title' => "{$token} Company Course"]);
        $version = CourseVersion::factory()->create([
            'course_id' => $course,
            'title' => $course->title,
            'description' => $course->description,
        ]);
        $ids = self::directLessons($version, $token, companyId: $course->company_id);

        return new self($context, $course, $user, [], $ids, $token);
    }

    private static function sharedCourse(EditorContextCase $context, string $token): self
    {
        $account = Account::factory()->platformAdmin()->create(['name' => "{$token} Platform Owner"]);
        $course = Course::factory()->shared()->draft()->create(['title' => "{$token} Shared Course"]);
        $version = CourseVersion::factory()->create([
            'course_id' => $course,
            'title' => $course->title,
            'description' => $course->description,
        ]);
        $ids = [];

        foreach (range(1, 3) as $position) {
            [, $module] = self::sharedModuleRecord("{$token} Shared {$position}", $position, questionCount: $position === 1 ? 3 : 1);
            CourseVersionModule::query()->create([
                'course_version_id' => $version->id,
                'lesson_id' => $module->id,
                'position' => $position,
                'is_required' => true,
            ]);
            $ids[] = $module->id;
        }

        return new self($context, $course, null, ['platform_account_id' => $account->id], $ids, $token);
    }

    private static function sharedModule(EditorContextCase $context, string $token): self
    {
        $account = Account::factory()->platformAdmin()->create(['name' => "{$token} Platform Owner"]);
        [$module, $version] = self::sharedModuleRecord("{$token} Standalone Module", 1, questionCount: 3);

        return new self($context, $module, null, ['platform_account_id' => $account->id], [$version->id], $token);
    }

    /** @return list<int> */
    private static function directLessons(CourseVersion $version, string $token, int $companyId): array
    {
        $ids = [];

        foreach (range(1, 3) as $position) {
            $lesson = Lesson::factory()->create([
                'company_id' => $companyId,
                'course_version_id' => $version->id,
                'title' => "{$token} Lesson {$position}",
                'content_markdown' => '',
                'position' => $position,
            ]);
            foreach (range(1, $position === 1 ? 3 : 1) as $questionPosition) {
                self::assessment($lesson, "{$token} Lesson {$position} Question {$questionPosition}", $questionPosition);
            }
            $ids[] = $lesson->id;
        }

        return $ids;
    }

    /** @return array{Module, ModuleVersion} */
    private static function sharedModuleRecord(string $title, int $position, int $questionCount = 1): array
    {
        $module = Module::factory()->shared()->create([
            'status' => 'active',
            'title' => $title,
            'description' => "{$title} description",
            'content_markdown' => '',
            'position' => $position,
        ]);
        $version = ModuleVersion::factory()->create([
            'module_id' => $module->id,
            'status' => 'draft',
            'title' => $title,
            'description' => "{$title} description",
            'content_markdown' => '',
            'position' => $position,
        ]);

        foreach (range(1, $questionCount) as $number) {
            self::assessment($version, "{$title} Question {$number}", $number);
        }

        return [$module, $version];
    }

    private static function assessment(Lesson $lesson, string $prompt, int $position = 1): void
    {
        $question = Question::factory()->create([
            'company_id' => $lesson->company_id,
            'lesson_id' => $lesson->id,
            'prompt' => $prompt,
            'position' => $position,
            'type' => 'single_choice',
        ]);

        QuestionOption::factory()->correct()->create([
            'company_id' => $lesson->company_id,
            'question_id' => $question->id,
            'position' => 1,
            'text' => "{$prompt} Answer A",
        ]);
        QuestionOption::factory()->create([
            'company_id' => $lesson->company_id,
            'question_id' => $question->id,
            'position' => 2,
            'text' => "{$prompt} Answer B",
        ]);
    }
}
