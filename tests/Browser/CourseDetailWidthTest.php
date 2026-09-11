<?php

use Tests\Support\CourseEditor\BrowserEnvironment;
use Tests\Support\CourseEditor\EditorContextCase;
use Tests\Support\CourseEditor\EditorFixture;

beforeEach(fn () => BrowserEnvironment::assertReady());

test('course detail text owns a wide row without changing unrelated heroes', function (string $contextName): void {
    $context = EditorContextCase::all()[$contextName];
    $fixture = EditorFixture::create($context);
    $fixture->root->update([
        'title' => str_repeat("{$fixture->token} Long course title ", 4),
        'description' => str_repeat('A deliberately long description must retain useful reading width beside independent actions. ', 8),
    ]);

    if ($fixture->user !== null) {
        $this->actingAs($fixture->user);
    }

    if ($fixture->session !== []) {
        $this->withSession($fixture->session);
    }

    $route = $context->name === 'company-course'
        ? route('courses.show', ['company' => $fixture->root->company, 'course' => $fixture->root], false)
        : route('platform.shared-courses.show', ['course' => $fixture->root], false);
    $page = visit($route)
        ->assertPresent('[data-course-hero][data-description-width="wide"]')
        ->assertPresent('[data-course-hero-title]')
        ->assertPresent('[data-course-hero-description]');

    foreach ([[1440, 900], [320, 700]] as [$width, $height]) {
        $page->resize($width, $height)->wait(0.2);
        $ratios = $page->script(<<<'JS'
            () => {
                return ['[data-course-hero-title]', '[data-course-hero-description]']
                    .map(selector => {
                        const element = document.querySelector(selector);
                        const parent = element.parentElement;
                        const style = getComputedStyle(parent);
                        const innerWidth = parent.clientWidth
                            - parseFloat(style.paddingLeft)
                            - parseFloat(style.paddingRight);
                        return element.getBoundingClientRect().width / innerWidth;
                    });
            }
        JS);

        foreach ($ratios as $ratio) {
            expect($ratio)->toBeGreaterThanOrEqual(0.98);
        }

        $overflow = $page->script(<<<'JS'
            () => [...document.querySelectorAll('body *')]
                .filter(element => element.getBoundingClientRect().right > window.innerWidth + 1)
                .slice(0, 8)
                .map(element => `${element.tagName.toLowerCase()}#${element.id}.${element.className}`)
        JS);
        if ($overflow !== []) {
            throw new RuntimeException('Overflowing elements: '.json_encode($overflow, JSON_THROW_ON_ERROR));
        }
        expect($overflow, 'Overflowing elements: '.json_encode($overflow))->toBeEmpty();
    }
})->with('course detail contexts');
