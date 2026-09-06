<?php

use App\Enums\Permission as PermissionEnum;
use App\Models\CourseVersion;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('upgrades an existing catalog additively and enables a real non-admin profile without seeding', function () {
    // Represent an installation before preview sharing, including an unrelated legacy row/grant.
    Permission::query()->whereIn('key', [PermissionEnum::CoursesGeneratePreviewLink->value, PermissionEnum::CoursesUpdate->value])->delete();
    $view = Permission::query()->firstOrCreate(['key' => 'courses.view'], ['label' => 'Existing view label', 'group' => 'courses']);
    $view->update(['label' => 'Existing customized view label']);
    $legacy = Permission::query()->create(['key' => 'legacy.retained', 'label' => 'Retained legacy permission', 'group' => 'legacy']);
    $oldRole = Role::factory()->create();
    $oldRole->permissions()->attach([$view->id, $legacy->id]);
    $beforeRows = DB::table('permissions')->orderBy('id')->get()->keyBy('id');
    $beforeGrants = DB::table('permission_role')->orderBy('id')->get()->toJson();
    $migration = require database_path('migrations/2026_09_06_011954_project_course_preview_permission_catalog.php');
    DB::table('migrations')->where('migration', '2026_09_06_011954_project_course_preview_permission_catalog')->delete();
    $this->artisan('migrate', ['--path' => 'database/migrations/2026_09_06_011954_project_course_preview_permission_catalog.php', '--force' => true])->assertSuccessful();
    foreach ($beforeRows as $id => $row) {
        expect((array) DB::table('permissions')->find($id))->toBe((array) $row);
    }
    expect(DB::table('permission_role')->orderBy('id')->get()->toJson())->toBe($beforeGrants);
    expect(Permission::whereIn('key', PermissionEnum::withPrerequisites([PermissionEnum::CoursesGeneratePreviewLink]))->count())->toBe(3);
    $snapshot = DB::table('permissions')->orderBy('id')->get()->toJson();
    $migration->up();
    expect(DB::table('permissions')->orderBy('id')->get()->toJson())->toBe($snapshot);
    $role = Role::factory()->create();
    $recipient = User::factory()->create();
    $recipient->roles()->attach($role);
    $version = CourseVersion::factory()->create();
    $url = route('courses.preview-link', ['course' => $version->course_id, 'version' => $version->id]);
    $this->actingAs($recipient)->postJson($url)->assertForbidden();
    Livewire::actingAs(adminUser())->test('admin.access-profile', ['role' => $role])
        ->set('selected', [PermissionEnum::CoursesGeneratePreviewLink->value])->call('save')->assertHasNoErrors();
    expect($role->permissions()->pluck('key')->all())->toEqualCanonicalizing(PermissionEnum::withPrerequisites([PermissionEnum::CoursesGeneratePreviewLink]));
    expect($recipient->fresh()->roles()->where('key', 'admin')->exists())->toBeFalse();
    $this->actingAs($recipient->fresh())->postJson($url)->assertCreated();
    $grants = DB::table('permission_role')->orderBy('id')->get()->toJson();
    $migration->down();
    expect(DB::table('permissions')->orderBy('id')->get()->toJson())->toBe($snapshot);
    expect(DB::table('permission_role')->orderBy('id')->get()->toJson())->toBe($grants);
});
