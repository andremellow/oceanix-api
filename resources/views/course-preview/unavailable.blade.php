@extends('course-preview.layout')
@section('content')
<section class="detail-card p-6 sm:p-8">
    <h1 class="text-2xl font-bold">{{ $message }}</h1>
    <p class="mt-4 text-[var(--ds-text-secondary)]">{{ __('Please contact the person who shared this link for more information.') }}</p>
</section>
@endsection
