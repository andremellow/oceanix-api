<?php

use App\Actions\Lgpd\AnonymizePerson;
use App\Models\AuditLog;
use App\Models\ComplianceEvent;
use App\Models\User;
use App\Models\UserTrainingAssignment;

it('destroys identity while preserving the compliance record', function (): void {
    [$assignment] = trainableAssignment();
    $user = $assignment->user;
    $user->update(['employee_id' => '48213', 'name' => 'Helena Vasques']);
    ComplianceEvent::factory()->create([
        'user_id' => $user->id,
        'assignment_id' => $assignment->id,
        'ip_address' => '10.0.0.9',
        'user_agent' => 'Safari on an iPad',
    ]);

    app(AnonymizePerson::class)->handle($user);

    $user->refresh();

    expect($user->name)->not->toBe('Helena Vasques')
        ->and($user->email)->toEndWith('@anonymized.invalid')
        ->and($user->employee_id)->toBeNull()
        ->and($user->workos_user_id)->toBeNull()
        // The obligation and its evidence survive: that is the point.
        ->and(UserTrainingAssignment::query()->whereKey($assignment->id)->exists())->toBeTrue()
        ->and(ComplianceEvent::query()->where('user_id', $user->id)->count())->toBeGreaterThan(0)
        ->and(ComplianceEvent::query()->where('user_id', $user->id)->whereNotNull('ip_address')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'person.anonymized')->count())->toBe(1);
});

it('only touches people terminated beyond the retention window', function (): void {
    $recent = User::factory()->terminated()->create(['terminated_at' => now()->subMonths(6)]);
    $old = User::factory()->terminated()->create(['terminated_at' => now()->subYears(7)]);
    $active = User::factory()->create();

    $this->artisan('oceanix:anonymize-terminated')->assertSuccessful();

    expect($old->fresh()->email)->toEndWith('@anonymized.invalid')
        ->and($recent->fresh()->email)->not->toEndWith('@anonymized.invalid')
        ->and($active->fresh()->email)->not->toEndWith('@anonymized.invalid');
});

it('changes nothing on a dry run', function (): void {
    $user = User::factory()->terminated()->create(['terminated_at' => now()->subYears(7)]);

    $this->artisan('oceanix:anonymize-terminated', ['--dry-run' => true])->assertSuccessful();

    expect($user->fresh()->email)->not->toEndWith('@anonymized.invalid');
});
