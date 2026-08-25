<?php

namespace Database\Seeders;

use App\Enums\AssignmentOrigin;
use App\Enums\AssignmentStatus;
use App\Enums\CourseStatus;
use App\Enums\CourseVersionStatus;
use App\Enums\FrequencyType;
use App\Enums\RenewalBasis;
use App\Enums\RequirementStatus;
use App\Enums\TargetScope;
use App\Enums\UserStatus;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Department;
use App\Models\JobFunction;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Role;
use App\Models\TrainingRequirement;
use App\Models\TrainingRequirementTarget;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use App\Models\Video;
use Illuminate\Database\Seeder;

/**
 * Offshore-flavored sample data for local development and visual review.
 *
 * Deliberately uneven: different names, deadlines and statuses, so a projection bug cannot
 * hide behind repeated values.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $departments = collect([
            ['name' => 'Operations', 'code' => 'OPS'],
            ['name' => 'Maintenance', 'code' => 'MNT'],
            ['name' => 'HSE', 'code' => 'HSE'],
            ['name' => 'Marine', 'code' => 'MAR'],
        ])->mapWithKeys(fn (array $attributes): array => [
            $attributes['code'] => Department::query()->updateOrCreate(
                ['code' => $attributes['code']],
                $attributes + ['status' => 'active'],
            ),
        ]);

        $functions = collect([
            ['name' => 'Supervisor', 'code' => 'SUP'],
            ['name' => 'Welder', 'code' => 'WLD'],
            ['name' => 'Deck operator', 'code' => 'DCK'],
            ['name' => 'Safety officer', 'code' => 'SAF'],
        ])->mapWithKeys(fn (array $attributes): array => [
            $attributes['code'] => JobFunction::query()->updateOrCreate(
                ['code' => $attributes['code']],
                $attributes + ['status' => 'active'],
            ),
        ]);

        $departments['OPS']->jobFunctions()->syncWithoutDetaching([$functions['SUP']->id, $functions['DCK']->id]);
        $departments['MNT']->jobFunctions()->syncWithoutDetaching([$functions['WLD']->id, $functions['SUP']->id]);
        $departments['HSE']->jobFunctions()->syncWithoutDetaching([$functions['SAF']->id]);
        $departments['MAR']->jobFunctions()->syncWithoutDetaching([$functions['DCK']->id]);

        $courses = collect([
            ['code' => 'HUET-01', 'title' => 'Helicopter underwater escape', 'description' => 'Escape procedures and breathing apparatus for offshore transfer.'],
            ['code' => 'OFF-SAFE', 'title' => 'Offshore safety fundamentals', 'description' => 'Platform hazards, permits to work, and emergency muster.'],
            ['code' => 'HOTWORK', 'title' => 'Hot work and welding permits', 'description' => 'Fire watch, gas testing and permit discipline for hot work.'],
        ])->map(function (array $attributes): Course {
            $course = Course::query()->updateOrCreate(['code' => $attributes['code']], $attributes);

            if ($course->versions()->exists()) {
                return $course->refresh();
            }

            $version = CourseVersion::query()->create([
                'course_id' => $course->id,
                'version_number' => 1,
                'status' => CourseVersionStatus::Published,
                'title' => $attributes['title'],
                'description' => $attributes['description'],
                'published_at' => now()->subMonths(2),
            ]);

            foreach ([
                ['title' => 'Hazards and controls', 'duration' => 640, 'watch' => 90],
                ['title' => 'Procedures in practice', 'duration' => 920, 'watch' => 95],
            ] as $index => $lessonData) {
                $lesson = Lesson::query()->create([
                    'course_version_id' => $version->id,
                    'title' => $lessonData['title'],
                    'position' => $index + 1,
                    'minimum_watch_percentage' => $lessonData['watch'],
                    'passing_score' => 70,
                ]);

                Video::query()->create([
                    'lesson_id' => $lesson->id,
                    // Demo data has no remote asset. Never manufacture a Cloudflare id:
                    // it looks playable locally but inevitably fails authorization later.
                    'provider' => 'demo_placeholder',
                    'provider_asset_id' => 'demo-placeholder-'.($index + 1),
                    'duration_seconds' => $lessonData['duration'],
                    'status' => 'failed',
                ]);

                $question = Question::query()->create([
                    'lesson_id' => $lesson->id,
                    'prompt' => 'Which action comes first when the muster alarm sounds?',
                    'position' => 1,
                    'max_attempts' => 3,
                ]);

                foreach ([
                    ['text' => 'Proceed to the assigned muster station', 'correct' => true],
                    ['text' => 'Collect personal belongings from the cabin', 'correct' => false],
                    ['text' => 'Wait for a second announcement', 'correct' => false],
                ] as $position => $option) {
                    QuestionOption::query()->create([
                        'question_id' => $question->id,
                        'text' => $option['text'],
                        'is_correct' => $option['correct'],
                        'position' => $position + 1,
                    ]);
                }
            }

            $course->update([
                'current_published_version_id' => $version->id,
                'status' => CourseStatus::Active,
            ]);

            return $course->refresh();
        })->keyBy('code');

        $requirement = TrainingRequirement::query()->updateOrCreate(
            ['name' => 'Offshore safety — Operations supervisors'],
            [
                'course_id' => $courses['OFF-SAFE']->id,
                'status' => RequirementStatus::Active,
                'frequency_type' => FrequencyType::Months,
                'frequency_value' => 6,
                'renewal_basis' => RenewalBasis::FromCompletion,
                'assignment_lead_days' => 30,
                'due_days_after_assignment' => 30,
            ],
        );

        if ($requirement->targets()->doesntExist()) {
            TrainingRequirementTarget::query()->create([
                'training_requirement_id' => $requirement->id,
                'scope_type' => TargetScope::DepartmentJobFunction,
                'department_id' => $departments['OPS']->id,
                'job_function_id' => $functions['SUP']->id,
            ]);
        }

        $employeeRole = Role::query()->where('key', 'employee')->first();

        $people = [
            ['name' => 'Marina Costa', 'email' => 'marina.costa@example.com', 'dept' => 'OPS', 'fn' => 'SUP', 'due' => -12, 'status' => AssignmentStatus::Overdue, 'course' => 'OFF-SAFE'],
            ['name' => 'Rafael Duarte', 'email' => 'rafael.duarte@example.com', 'dept' => 'MNT', 'fn' => 'WLD', 'due' => 6, 'status' => AssignmentStatus::InProgress, 'course' => 'HOTWORK'],
            ['name' => 'Helena Vasques', 'email' => 'helena.vasques@example.com', 'dept' => 'HSE', 'fn' => 'SAF', 'due' => 44, 'status' => AssignmentStatus::Pending, 'course' => 'HUET-01'],
            ['name' => 'Tomás Ferreira', 'email' => 'tomas.ferreira@example.com', 'dept' => 'MAR', 'fn' => 'DCK', 'due' => -71, 'status' => AssignmentStatus::Overdue, 'course' => 'HUET-01'],
            ['name' => 'Bianca Nogueira', 'email' => 'bianca.nogueira@example.com', 'dept' => 'OPS', 'fn' => 'DCK', 'due' => 18, 'status' => AssignmentStatus::Completed, 'course' => 'OFF-SAFE'],
        ];

        $departmentPlan = [
            'OPS' => ['label' => 'Operations', 'functions' => ['SUP', 'DCK']],
            'MNT' => ['label' => 'Maintenance', 'functions' => ['WLD', 'SUP']],
            'HSE' => ['label' => 'HSE', 'functions' => ['SAF']],
            'MAR' => ['label' => 'Marine', 'functions' => ['DCK']],
        ];
        $statusPlan = [
            AssignmentStatus::Pending,
            AssignmentStatus::InProgress,
            AssignmentStatus::Overdue,
            AssignmentStatus::Completed,
        ];

        for ($index = count($people); $index < 50; $index++) {
            $departmentCode = array_keys($departmentPlan)[$index % count($departmentPlan)];
            $plan = $departmentPlan[$departmentCode];
            $number = $index + 1;
            $status = $statusPlan[$index % count($statusPlan)];

            $people[] = [
                'name' => sprintf('%s Employee %02d', $plan['label'], $number),
                'email' => sprintf('demo.%s.%02d@example.com', strtolower($departmentCode), $number),
                'dept' => $departmentCode,
                'fn' => $plan['functions'][$index % count($plan['functions'])],
                'due' => $status === AssignmentStatus::Overdue ? -($number % 45 + 1) : ($number % 75 + 5),
                'status' => $status,
                'course' => ['OFF-SAFE', 'HOTWORK', 'HUET-01'][$index % 3],
            ];
        }

        foreach ($people as $index => $person) {
            $account = Account::query()->firstOrCreate(
                ['email' => $person['email']],
                ['name' => $person['name'], 'status' => 'active'],
            );
            $user = User::query()->updateOrCreate(
                ['email' => $person['email']],
                [
                    'name' => $person['name'],
                    'account_id' => $account->id,
                    'email_verified_at' => now(),
                    'employee_id' => (string) (48200 + $index),
                    'status' => UserStatus::Active,
                    'hired_at' => now()->subYears(2)->subDays($index * 37),
                ],
            );

            $user->departments()->syncWithoutDetaching([$departments[$person['dept']]->id]);
            $user->jobFunctions()->syncWithoutDetaching([$functions[$person['fn']]->id]);

            if ($employeeRole !== null) {
                $user->roles()->syncWithoutDetaching([$employeeRole->id]);
            }

            $course = $courses[$person['course']];

            UserTrainingAssignment::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'course_id' => $course->id,
                    'cycle_number' => 1,
                ],
                [
                    'course_version_id' => $course->current_published_version_id,
                    'origin_type' => $person['course'] === 'OFF-SAFE'
                        ? AssignmentOrigin::Requirement
                        : AssignmentOrigin::Manual,
                    'training_requirement_id' => $person['course'] === 'OFF-SAFE' ? $requirement->id : null,
                    'assigned_at' => now()->subDays(40),
                    'due_at' => now()->addDays($person['due']),
                    'status' => $person['status'],
                    'completed_at' => $person['status'] === AssignmentStatus::Completed ? now()->subDays(4) : null,
                ],
            );
        }

        // Predictable manager scopes make recursive dashboard testing easy in local data.
        User::query()->where('email', 'marina.costa@example.com')->first()?->managedDepartments()->syncWithoutDetaching([$departments['OPS']->id]);
        User::query()->where('email', 'rafael.duarte@example.com')->first()?->managedDepartments()->syncWithoutDetaching([$departments['MNT']->id]);
        User::query()->where('email', 'helena.vasques@example.com')->first()?->managedDepartments()->syncWithoutDetaching([$departments['HSE']->id]);
        User::query()->where('email', 'tomas.ferreira@example.com')->first()?->managedDepartments()->syncWithoutDetaching([$departments['MAR']->id]);

        // This Operations employee manages Maintenance, creating a visible second level
        // below Marina for exercising recursive manager visibility.
        User::query()->where('email', 'demo.ops.09@example.com')->first()?->managedDepartments()->syncWithoutDetaching([$departments['MNT']->id]);
    }
}
