<?php

use App\Models\Course;
use App\Models\Lesson;
use Livewire\Component;

new class extends Component
{
    public Course $course;

    public Lesson $lesson;

    public function mount(Course $course, Lesson $lesson): void
    {
        abort_if($course->is_shared, 404);
        $this->authorize('update', $course);
        abort_unless($course->versions()->whereKey($lesson->course_version_id)->exists(), 404);

        $this->course = $course;
        $this->lesson = $lesson->load('video');
    }
};
?>

<div class="admin-page">
    <x-page-hero :kicker="__('Content preview')" :title="$lesson->title" :description="$course->title">
        <flux:button href="#" onclick="window.close(); return false;" variant="ghost">{{ __('Close preview') }}</flux:button>
    </x-page-hero>

    <article class="mx-auto mt-7 max-w-5xl rounded-[24px] border border-[#dce3e7] bg-white px-6 py-8 shadow-sm sm:px-10 sm:py-12">
        <x-lesson-content :lesson="$lesson" />
    </article>
</div>
