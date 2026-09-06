<?php

use App\Models\UserTrainingAssignment;
use Livewire\Component;

new class extends Component
{
    public UserTrainingAssignment $assignment;

    public function mount(UserTrainingAssignment $assignment): void
    {
        // Re-authorize on every hydration: revoking an assignment must take effect even on
        // a page that was already open.
        $this->authorize('view', $assignment);

        $this->assignment = $assignment->load([
            'course', 'courseVersion.lessons.video', 'courseVersion.lessons.questions',
            'courseVersion.moduleCompositions.moduleVersion.video',
            'courseVersion.moduleCompositions.moduleVersion.questions', 'certificate',
        ]);
    }

    public function with(): array
    {
        $version = $this->assignment->courseVersion;
        $lessons = $version->moduleCompositions->isNotEmpty()
            ? $version->moduleCompositions->pluck('moduleVersion')->filter()->values()
            : $version->lessons;

        return [
            'lessons' => $lessons,
            'progress' => $this->assignment->lessonProgress()->get()->keyBy('lesson_id'),
        ];
    }
};
?>

<x-training.course-overview
    :$assignment
    :course="$assignment->course"
    :version="$assignment->courseVersion"
    :$lessons
    :$progress
    :back-url="route('my-training')"
    :can-execute="auth()->user()->can('execute', $assignment)" />
