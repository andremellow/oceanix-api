<?php

namespace App\Services\People;

use App\Models\Department;
use App\Models\JobFunction;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PeopleImportPreview
{
    /**
     * @param  list<array{row: int, name: string, email: string, job_function: string, department: string}>  $rows
     * @return array{rows: list<array<string, mixed>>, errors: list<string>, job_functions: list<array<string, mixed>>, departments: list<array<string, mixed>>}
     */
    public function prepare(array $rows): array
    {
        $errors = [];
        $emails = [];

        foreach ($rows as $row) {
            if ($row['name'] === '') {
                $errors[] = __('Row :row has no name.', ['row' => $row['row']]);
            }

            if (! filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = __('Row :row has an invalid email address.', ['row' => $row['row']]);
            }

            if ($row['email'] !== '' && isset($emails[$row['email']])) {
                $errors[] = __('Rows :first and :second use the same email address.', [
                    'first' => $emails[$row['email']],
                    'second' => $row['row'],
                ]);
            }

            $emails[$row['email']] = $row['row'];
        }

        return [
            'rows' => $rows,
            'errors' => array_values(array_unique($errors)),
            'job_functions' => $this->terms($rows, 'job_function', JobFunction::query()->orderBy('name')->get()),
            'departments' => $this->terms($rows, 'department', Department::query()->orderBy('name')->get()),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, JobFunction|Department>  $existing
     * @return list<array{value: string, count: int, selected: string, matched: bool}>
     */
    private function terms(array $rows, string $key, Collection $existing): array
    {
        $byNormalizedName = $existing->keyBy(fn ($model): string => $this->normalize($model->name));

        return collect($rows)
            ->pluck($key)
            ->filter()
            ->countBy()
            ->sortKeys()
            ->map(function (int $count, string $value) use ($byNormalizedName): array {
                $match = $byNormalizedName->get($this->normalize($value));

                return [
                    'value' => $value,
                    'count' => $count,
                    'selected' => $match === null ? 'create' : (string) $match->id,
                    'matched' => $match !== null,
                ];
            })
            ->values()
            ->all();
    }

    private function normalize(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }
}
