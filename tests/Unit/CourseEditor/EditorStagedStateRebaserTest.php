<?php

use App\Services\CourseEditor\EditorStagedStateRebaser;
use Illuminate\Validation\ValidationException;

function authoredEditorGraph(): array
{
    return [
        'course' => ['id' => 1, 'title' => 'Course', 'code' => 'C-1'],
        'version' => ['id' => 2, 'title' => 'Version', 'description' => 'Description'],
        'records' => [[
            'id' => 10,
            'key' => 'lesson:10',
            'title' => 'Lesson',
            'position' => 1,
            'questions' => [[
                'id' => 20,
                'key' => 'question:20',
                'prompt' => 'Prompt',
                'position' => 1,
                'options' => [
                    ['id' => 30, 'key' => 'option:30', 'text' => 'First', 'is_correct' => true, 'position' => 1],
                    ['id' => 31, 'key' => 'option:31', 'text' => '', 'is_correct' => false, 'position' => 2],
                ],
            ]],
        ]],
    ];
}

it('rebases authored leaves by stable identity across canonical reorder and additions', function (): void {
    $rebaser = new EditorStagedStateRebaser;
    $stagedGraph = authoredEditorGraph();
    $stagedGraph['records'][0]['questions'][0]['options'][1]['text'] = 'Unsaved answer';
    $canonical = authoredEditorGraph();
    $canonical['records'][0]['questions'][0]['options'] = [
        ['id' => 32, 'key' => 'option:32', 'text' => '', 'is_correct' => false, 'position' => 1],
        ['id' => 31, 'key' => 'option:31', 'text' => '', 'is_correct' => false, 'position' => 2],
        ['id' => 30, 'key' => 'option:30', 'text' => 'First', 'is_correct' => true, 'position' => 3],
    ];

    $staged = $rebaser->capture(
        $stagedGraph['course'],
        $stagedGraph['version'],
        $stagedGraph['records'],
        ['lesson:10'],
        ['records.0.questions.0.options.1.text' => ['The answer is required.']],
        7,
        true,
        'validation-error',
        'validation-error',
        'Some changes need attention.',
        'records.0.questions.0.options.1.text',
    );
    $result = $rebaser->rebase($canonical['course'], $canonical['version'], $canonical['records'], $staged);

    expect($result->records[0]['questions'][0]['options'][0]['key'])->toBe('option:32')
        ->and($result->records[0]['questions'][0]['options'][1]['text'])->toBe('Unsaved answer')
        ->and($result->records[0]['questions'][0]['options'][2]['position'])->toBe(3)
        ->and($result->validationMessages)->toHaveKey('records.0.questions.0.options.1.text')
        ->and($result->focusInvalidField)->toBe('records.0.questions.0.options.1.text')
        ->and($result->dirty)->toBeTrue()
        ->and($result->generation)->toBe(7);
});

it('drops only the confirmed removed subtree while retaining unrelated authored leaves', function (): void {
    $rebaser = new EditorStagedStateRebaser;
    $stagedGraph = authoredEditorGraph();
    $stagedGraph['course']['title'] = 'Unsaved course';
    $stagedGraph['records'][0]['questions'][0]['options'][1]['text'] = 'Removed answer';
    $canonical = authoredEditorGraph();
    array_pop($canonical['records'][0]['questions'][0]['options']);

    $staged = $rebaser->capture(
        $stagedGraph['course'],
        $stagedGraph['version'],
        $stagedGraph['records'],
        [],
        ['records.0.questions.0.options.1.text' => ['Invalid answer']],
        2,
        true,
        'validation-error',
        'validation-error',
        'Some changes need attention.',
        'records.0.questions.0.options.1.text',
    );
    $result = $rebaser->rebase($canonical['course'], $canonical['version'], $canonical['records'], $staged, ['option:31']);

    expect($result->course['title'])->toBe('Unsaved course')
        ->and($result->records[0]['questions'][0]['options'])->toHaveCount(1)
        ->and($result->validationMessages)->toBe([])
        ->and($result->focusInvalidField)->toBeNull()
        ->and($result->saveState)->toBe('dirty')
        ->and($result->droppedRecordKeys)->toBe(['option:31'])
        ->and($result->dirty)->toBeTrue();
});

it('drops an exact confirmed question subtree while preserving a staged sibling and its validation provenance', function (): void {
    $rebaser = new EditorStagedStateRebaser;
    $graph = authoredEditorGraph();
    $sibling = $graph['records'][0]['questions'][0];
    $sibling['id'] = 21;
    $sibling['key'] = 'question:21';
    $sibling['prompt'] = 'Staged sibling prompt';
    $sibling['options'][0]['id'] = 32;
    $sibling['options'][0]['key'] = 'option:32';
    $sibling['options'][1]['id'] = 33;
    $sibling['options'][1]['key'] = 'option:33';
    $graph['records'][0]['questions'][] = $sibling;
    $canonical = $graph;
    $canonical['records'][0]['questions'][1]['prompt'] = 'Canonical sibling prompt';
    array_shift($canonical['records'][0]['questions']);
    $staged = $rebaser->capture($graph['course'], $graph['version'], $graph['records'], [], [
        'records.0.questions.1.prompt' => ['Review the sibling prompt.'],
    ], 8, true, 'validation-error', 'validation-error', 'Review the sibling prompt.', 'records.0.questions.1.prompt');

    $result = $rebaser->rebase($canonical['course'], $canonical['version'], $canonical['records'], $staged, ['question:20']);

    expect($result->records[0]['questions'])->toHaveCount(1)
        ->and($result->records[0]['questions'][0]['key'])->toBe('question:21')
        ->and($result->records[0]['questions'][0]['prompt'])->toBe('Staged sibling prompt')
        ->and($result->validationMessages)->toHaveKey('records.0.questions.0.prompt')
        ->and($result->dirty)->toBeTrue();
});

it('drops an exact confirmed record subtree while preserving staged fields on a stable sibling', function (): void {
    $rebaser = new EditorStagedStateRebaser;
    $graph = authoredEditorGraph();
    $sibling = $graph['records'][0];
    $sibling['id'] = 11;
    $sibling['key'] = 'lesson:11';
    $sibling['title'] = 'Staged sibling lesson';
    $sibling['questions'] = [];
    $graph['records'][] = $sibling;
    $canonical = $graph;
    $canonical['records'][1]['title'] = 'Canonical sibling lesson';
    array_shift($canonical['records']);
    $staged = $rebaser->capture($graph['course'], $graph['version'], $graph['records'], ['lesson:11'], [], 9, true, 'dirty', null, null, null);

    $result = $rebaser->rebase($canonical['course'], $canonical['version'], $canonical['records'], $staged, ['lesson:10']);

    expect($result->records)->toHaveCount(1)
        ->and($result->records[0]['key'])->toBe('lesson:11')
        ->and($result->records[0]['title'])->toBe('Staged sibling lesson')
        ->and($result->dirtyContentKeys)->toBe(['lesson:11'])
        ->and($result->dirty)->toBeTrue();
});

it('fails safely for malformed duplicate temporary and cross-parent identity graphs', function (string $case): void {
    $rebaser = new EditorStagedStateRebaser;
    $graph = authoredEditorGraph();

    if ($case === 'duplicate') {
        $graph['records'][0]['questions'][0]['options'][1]['key'] = 'option:30';
        $rebaser->capture($graph['course'], $graph['version'], $graph['records'], [], [], 1, true, 'dirty', null, null, null);
    } elseif ($case === 'temporary') {
        $graph['records'][0]['questions'][0]['options'][1]['key'] = 'tmp:answer';
        $rebaser->capture($graph['course'], $graph['version'], $graph['records'], [], [], 1, true, 'dirty', null, null, null);
    } else {
        $staged = $rebaser->capture($graph['course'], $graph['version'], $graph['records'], [], [], 1, true, 'dirty', null, null, null);
        $second = $graph['records'][0];
        $second['id'] = 11;
        $second['key'] = 'lesson:11';
        $second['questions'] = $graph['records'][0]['questions'];
        $graph['records'][0]['questions'] = [];
        $graph['records'][] = $second;
        $rebaser->rebase($graph['course'], $graph['version'], $graph['records'], $staged);
    }
})->throws(ValidationException::class)->with(['duplicate', 'temporary', 'cross-parent']);
