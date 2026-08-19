<?php

namespace App\Enums;

enum TargetScope: string
{
    case Everyone = 'everyone';
    case Department = 'department';
    case JobFunction = 'job_function';
    case DepartmentJobFunction = 'department_job_function';

    public function label(): string
    {
        return __(match ($this) {
            self::Everyone => 'Everyone',
            self::Department => 'Department',
            self::JobFunction => 'Job function',
            self::DepartmentJobFunction => 'Department and job function',
        });
    }

    public function requiresDepartment(): bool
    {
        return in_array($this, [self::Department, self::DepartmentJobFunction], true);
    }

    public function requiresJobFunction(): bool
    {
        return in_array($this, [self::JobFunction, self::DepartmentJobFunction], true);
    }
}
