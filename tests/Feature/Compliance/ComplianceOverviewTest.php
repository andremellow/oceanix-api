<?php

use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Department;
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
