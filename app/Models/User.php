<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name', 'email', 'email_verified_at', 'password', 'avatar_url', 'provider', 'provider_id',
    'workos_user_id', 'employee_id', 'status', 'hired_at', 'terminated_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'hired_at' => 'date',
            'terminated_at' => 'date',
        ];
    }

    /**
     * @return BelongsToMany<Department, $this>
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'user_department')
            ->withPivot(['starts_at', 'ends_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<JobFunction, $this>
     */
    public function jobFunctions(): BelongsToMany
    {
        return $this->belongsToMany(JobFunction::class, 'user_job_function')
            ->withPivot(['starts_at', 'ends_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    /**
     * @return HasMany<UserTrainingAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(UserTrainingAssignment::class);
    }

    /**
     * @return HasMany<Certificate, $this>
     */
    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    /**
     * @return HasMany<ComplianceEvent, $this>
     */
    public function complianceEvents(): HasMany
    {
        return $this->hasMany(ComplianceEvent::class);
    }

    public function hasRole(string $role): bool
    {
        return $this->roles()->where('key', strtolower($role))->exists();
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * The control center is the operational shell. Employees without any granted
     * permission only see their own training, never the administrative screens.
     */
    public function canAccessControlCenter(): bool
    {
        return $this->isAdmin() || $this->roles()
            ->whereNull('archived_at')
            ->whereHas('permissions')
            ->exists();
    }

    /** Resolves the union of the user's active access profiles. Used behind Gates only. */
    public function hasPermission(string|Permission $permission): bool
    {
        $key = $permission instanceof Permission ? $permission->value : $permission;

        return $this->roles()
            ->whereNull('archived_at')
            ->whereHas('permissions', fn (Builder $query) => $query->where('key', $key))
            ->exists();
    }

    /** @return list<string> */
    public function effectivePermissionKeys(): array
    {
        if ($this->isAdmin()) {
            return Permission::values();
        }

        return $this->roles()->whereNull('archived_at')->with('permissions:id,key')->get()
            ->flatMap(fn (Role $role): Collection => $role->permissions)
            ->pluck('key')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function initial(): string
    {
        return str($this->name)->substr(0, 1)->upper()->toString();
    }

    /** Departments the user belongs to today, honoring effective dates. */
    public function scopeInDepartment(Builder $query, int $departmentId): void
    {
        $query->whereHas('departments', function (Builder $relation) use ($departmentId): void {
            $relation->where('departments.id', $departmentId)
                ->where(fn (Builder $dates) => $dates->whereNull('user_department.ends_at')
                    ->orWhere('user_department.ends_at', '>=', now()->toDateString()));
        });
    }

    public function scopeInJobFunction(Builder $query, int $jobFunctionId): void
    {
        $query->whereHas('jobFunctions', function (Builder $relation) use ($jobFunctionId): void {
            $relation->where('job_functions.id', $jobFunctionId)
                ->where(fn (Builder $dates) => $dates->whereNull('user_job_function.ends_at')
                    ->orWhere('user_job_function.ends_at', '>=', now()->toDateString()));
        });
    }

    public function scopeEligibleForTraining(Builder $query): void
    {
        $query->where('status', UserStatus::Active->value);
    }
}
