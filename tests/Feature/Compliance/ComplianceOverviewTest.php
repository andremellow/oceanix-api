<?php

use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Department;
use App\Models\JobFunction;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use App\Services\Compliance\ComplianceOverview;

function publishedCourse(string $code = 'SAFE-1'): Course
{
    $course = Course::factory()->create(['code' => $code]);
    $version = CourseVersion::factory()->published()->create(['course_id' => $course->id]);
    $course->update(['current_published_version_id' => $version->id]);

    return $course->refresh();
}

it('counts overdue, due soon and critical separately', function (): void {
    $course = publishedCourse();

    UserTrainingAssignment::factory()->forCourse($course)->overdue(45)->create(['user_id' => User::factory()]);
    UserTrainingAssignment::factory()->forCourse($course)->overdue(3)->create(['user_id' => User::factory()]);
    UserTrainingAssignment::factory()->forCourse($course)->create([
        'user_id' => User::factory(),
        'due_at' => now()->addDays(5),
    ]);
    UserTrainingAssignment::factory()->forCourse($course)->create([
        'user_id' => User::factory(),
        'due_at' => now()->addDays(200),
    ]);

    $metrics = app(ComplianceOverview::class)->metrics();

    expect($metrics['overdue'])->toBe(2)
        ->and($metrics['critical_overdue'])->toBe(1)
        ->and($metrics['due_soon'])->toBe(1)
        ->and($metrics['people'])->toBe(4)
        ->and($metrics['compliant'])->toBe(2);
});

it('derives compliance from assignments, not from the current department', function (): void {
    $course = publishedCourse();
    $department = Department::factory()->create();
    $user = User::factory()->create();
    $user->departments()->attach($department);

    UserTrainingAssignment::factory()->forCourse($course)->overdue()->create(['user_id' => $user->id]);

    // Moving the person out of the department does not clear the materialized obligation.
    $user->departments()->detach();

    expect(app(ComplianceOverview::class)->metrics()['overdue'])->toBe(1);
});

it('filters the operational table by department', function (): void {
    $course = publishedCourse();
    $operations = Department::factory()->create(['name' => 'Operations', 'code' => 'OPS']);
    $marine = Department::factory()->create(['name' => 'Marine', 'code' => 'MAR']);

    $inScope = User::factory()->create(['name' => 'Marina Costa']);
    $inScope->departments()->attach($operations);
    $outOfScope = User::factory()->create(['name' => 'Tomas Ferreira']);
    $outOfScope->departments()->attach($marine);

    UserTrainingAssignment::factory()->forCourse($course)->create(['user_id' => $inScope->id]);
    UserTrainingAssignment::factory()->forCourse($course)->create(['user_id' => $outOfScope->id]);

    $rows = app(ComplianceOverview::class)
        ->assignments(['department_id' => $operations->id])
        ->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->user_id)->toBe($inScope->id);
});

it('bands overdue assignments into the reporting buckets', function (): void {
    $course = publishedCourse();

    UserTrainingAssignment::factory()->forCourse($course)->overdue(3)->create(['user_id' => User::factory()]);
    UserTrainingAssignment::factory()->forCourse($course)->overdue(20)->create(['user_id' => User::factory()]);
    UserTrainingAssignment::factory()->forCourse($course)->overdue(90)->create(['user_id' => User::factory()]);

    $overview = app(ComplianceOverview::class);

    expect($overview->assignments(['due_bucket' => 'overdue_1_7'])->count())->toBe(1)
        ->and($overview->assignments(['due_bucket' => 'overdue_8_30'])->count())->toBe(1)
        ->and($overview->assignments(['due_bucket' => 'overdue_60_plus'])->count())->toBe(1);
});

it('limits metrics and assignments to the managers recursive organization tree', function (): void {
    $course = publishedCourse();
    $department = Department::factory()->create();
    $nestedFunction = JobFunction::factory()->create();
    $manager = User::factory()->create();
    $direct = User::factory()->create();
    $nested = User::factory()->create();
    $outside = User::factory()->create();

    $manager->managedDepartments()->attach($department);
    $direct->departments()->attach($department);
    $direct->managedJobFunctions()->attach($nestedFunction);
    $nested->jobFunctions()->attach($nestedFunction);

    UserTrainingAssignment::factory()->forCourse($course)->overdue()->create(['user_id' => $direct]);
    UserTrainingAssignment::factory()->forCourse($course)->create(['user_id' => $nested]);
    UserTrainingAssignment::factory()->forCourse($course)->create(['user_id' => $outside]);

    $overview = app(ComplianceOverview::class);
    $metrics = $overview->metrics($manager);
    $visibleUserIds = $overview->assignments(viewer: $manager)->pluck('user_id')->all();

    expect($metrics['people'])->toBe(2)
        ->and($metrics['overdue'])->toBe(1)
        ->and($visibleUserIds)->toContain($direct->id, $nested->id)
        ->not->toContain($outside->id);
});

it('builds assignment filters only from assignments in the managers scope', function (): void {
    $visibleCourse = publishedCourse('VISIBLE');
    $hiddenCourse = publishedCourse('HIDDEN');
    $unusedCourse = publishedCourse('UNUSED');
    $visibleDepartment = Department::factory()->create(['name' => 'Visible Department']);
    $hiddenDepartment = Department::factory()->create(['name' => 'Hidden Department']);
    $visibleFunction = JobFunction::factory()->create(['name' => 'Visible Function']);
    $hiddenFunction = JobFunction::factory()->create(['name' => 'Hidden Function']);
    $manager = User::factory()->create();
    $visible = User::factory()->create();
    $hidden = User::factory()->create();

    $manager->managedDepartments()->attach($visibleDepartment);
    $visible->departments()->attach($visibleDepartment);
    $visible->jobFunctions()->attach($visibleFunction);
    $hidden->departments()->attach($hiddenDepartment);
    $hidden->jobFunctions()->attach($hiddenFunction);
    UserTrainingAssignment::factory()->forCourse($visibleCourse)->create(['user_id' => $visible]);
    UserTrainingAssignment::factory()->forCourse($hiddenCourse)->create(['user_id' => $hidden]);

    $facets = app(ComplianceOverview::class)->assignmentFacets($manager);

    expect($facets['departments']->modelKeys())->toBe([$visibleDepartment->id])
        ->and($facets['jobFunctions']->modelKeys())->toBe([$visibleFunction->id])
        ->and($facets['courses']->modelKeys())->toBe([$visibleCourse->id])
        ->and($facets['courses']->modelKeys())->not->toContain($hiddenCourse->id, $unusedCourse->id);
});
