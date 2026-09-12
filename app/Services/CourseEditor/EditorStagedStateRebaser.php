<?php

namespace App\Services\CourseEditor;

use Illuminate\Validation\ValidationException;

final class EditorStagedStateRebaser
{
    private const ROOT_FIELDS = [
        'course' => ['code', 'title', 'description'],
        'version' => ['title', 'description'],
    ];

    private const RECORD_FIELDS = ['title', 'description', 'content_markdown', 'is_required', 'minimum_watch_percentage', 'passing_score'];

    private const QUESTION_FIELDS = ['prompt', 'type', 'max_attempts'];

    private const OPTION_FIELDS = ['text', 'is_correct'];

    /**
     * @param  array<string, mixed>|null  $course
     * @param  array<string, mixed>  $version
     * @param  list<array<string, mixed>>  $records
     * @param  list<string>  $dirtyContentKeys
     * @param  array<string, list<string>>  $validationMessages
     */
    public function capture(
        ?array $course,
        array $version,
        array $records,
        array $dirtyContentKeys,
        array $validationMessages,
        int $generation,
        bool $dirty,
        string $saveState,
        ?string $errorKind,
        ?string $saveError,
        ?string $focusInvalidField,
    ): EditorStagedState {
        $index = $this->index($course, $version, $records);
        $stableErrors = [];

        foreach ($validationMessages as $position => $messages) {
            $stable = $index['position_to_stable'][$position] ?? null;
            if ($stable !== null) {
                $stableErrors[$stable] = array_values($messages);
            }
        }

        return new EditorStagedState(
            $index['values'],
            $index['positions'],
            $stableErrors,
            array_values(array_unique($dirtyContentKeys)),
            $generation,
            $dirty,
            $saveState,
            $errorKind,
            $saveError,
            $focusInvalidField === null ? null : ($index['position_to_stable'][$focusInvalidField] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>|null  $course
     * @param  array<string, mixed>  $version
     * @param  list<array<string, mixed>>  $records
     * @param  list<string>  $removedRecordKeys
     */
    public function rebase(
        ?array $course,
        array $version,
        array $records,
        EditorStagedState $staged,
        array $removedRecordKeys = [],
    ): EditorRebaseResult {
        $canonical = $this->index($course, $version, $records);
        if (! $staged->dirty) {
            return new EditorRebaseResult(
                $course,
                $version,
                $records,
                [],
                [],
                $staged->generation,
                false,
                'clean',
                null,
                null,
                null,
                array_values(array_unique($removedRecordKeys)),
            );
        }
        $rebased = ['course' => $course, 'version' => $version, 'records' => $records];
        $retained = false;

        foreach ($staged->values as $stable => $value) {
            $position = $canonical['positions'][$stable] ?? null;
            if ($position === null) {
                if ($this->belongsToRemovedRecord($stable, $removedRecordKeys)) {
                    continue;
                }

                throw ValidationException::withMessages([
                    'structure' => __('The editor structure changed unexpectedly. Reload the latest draft before continuing.'),
                ]);
            }

            if (($canonical['values'][$stable] ?? null) !== $value) {
                $retained = true;
            }
            $dataPosition = str_replace(['courseForm.', 'versionForm.'], ['course.', 'version.'], $position);
            data_set($rebased, $dataPosition, $value);
        }

        $validationMessages = [];
        foreach ($staged->validationMessages as $stable => $messages) {
            $position = $canonical['positions'][$stable] ?? null;
            if ($position !== null) {
                $validationMessages[$position] = $messages;
            }
        }

        $focusInvalidField = $staged->focusInvalidStableField === null
            ? null
            : ($canonical['positions'][$staged->focusInvalidStableField] ?? null);
        $recordKeys = array_column($records, 'key');
        $dirtyContentKeys = array_values(array_intersect($staged->dirtyContentKeys, $recordKeys));
        $dirty = $staged->dirty && $retained;
        $state = $dirty ? $staged->saveState : 'clean';
        $errorKind = $dirty ? $staged->errorKind : null;
        $saveError = $dirty ? $staged->saveError : null;
        if ($state === 'validation-error' && $validationMessages === []) {
            $state = 'dirty';
            $errorKind = null;
            $saveError = null;
        }

        return new EditorRebaseResult(
            $rebased['course'],
            $rebased['version'],
            $rebased['records'],
            $validationMessages,
            $dirtyContentKeys,
            $staged->generation,
            $dirty,
            $state,
            $errorKind,
            $saveError,
            $focusInvalidField,
            array_values(array_unique($removedRecordKeys)),
        );
    }

    /**
     * @param  array<string, mixed>|null  $course
     * @param  array<string, mixed>  $version
     * @param  list<array<string, mixed>>  $records
     * @return array{values: array<string, mixed>, positions: array<string, string>, position_to_stable: array<string, string>}
     */
    private function index(?array $course, array $version, array $records): array
    {
        $values = [];
        $positions = [];
        $positionToStable = [];
        $identities = [];
        $register = function (string $identity, string $prefix, array $source, array $fields) use (&$values, &$positions, &$positionToStable, &$identities): void {
            $this->assertIdentity($identity);
            if (isset($identities[$identity])) {
                throw ValidationException::withMessages(['structure' => __('The editor contains duplicate record identities.')]);
            }
            $identities[$identity] = true;
            foreach ($fields as $field) {
                if (! array_key_exists($field, $source)) {
                    continue;
                }
                $stable = $identity.'/'.$field;
                $position = $prefix.'.'.$field;
                $values[$stable] = $source[$field];
                $positions[$stable] = $position;
                $positionToStable[$position] = $stable;
            }
        };

        if ($course !== null) {
            $register((string) ($course['key'] ?? 'course:'.($course['id'] ?? '')), 'courseForm', $course, self::ROOT_FIELDS['course']);
        }
        $register((string) ($version['key'] ?? 'version:'.($version['id'] ?? '')), 'versionForm', $version, self::ROOT_FIELDS['version']);

        foreach ($records as $recordIndex => $record) {
            $recordKey = (string) ($record['key'] ?? '');
            $recordPrefix = 'records.'.$recordIndex;
            $register($recordKey, $recordPrefix, $record, self::RECORD_FIELDS);
            foreach ($record['questions'] ?? [] as $questionIndex => $question) {
                $questionKey = (string) ($question['key'] ?? '');
                $questionIdentity = $recordKey.'/'.$questionKey;
                $questionPrefix = $recordPrefix.'.questions.'.$questionIndex;
                $register($questionIdentity, $questionPrefix, $question, self::QUESTION_FIELDS);
                foreach ($question['options'] ?? [] as $optionIndex => $option) {
                    $optionKey = (string) ($option['key'] ?? '');
                    $register(
                        $questionIdentity.'/'.$optionKey,
                        $questionPrefix.'.options.'.$optionIndex,
                        $option,
                        self::OPTION_FIELDS,
                    );
                }
            }
        }

        return ['values' => $values, 'positions' => $positions, 'position_to_stable' => $positionToStable];
    }

    private function assertIdentity(string $identity): void
    {
        foreach (explode('/', $identity) as $segment) {
            if (preg_match('/^(?:course|version|lesson|module-version|question|option):[1-9]\d*$/', $segment) !== 1) {
                throw ValidationException::withMessages(['structure' => __('The editor contains an invalid record identity.')]);
            }
        }
    }

    /** @param list<string> $removedRecordKeys */
    private function belongsToRemovedRecord(string $stable, array $removedRecordKeys): bool
    {
        $segments = explode('/', $stable);

        return collect($removedRecordKeys)->contains(fn (string $key): bool => in_array($key, $segments, true));
    }
}
