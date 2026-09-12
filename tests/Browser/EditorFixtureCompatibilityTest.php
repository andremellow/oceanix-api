<?php

use Tests\Support\CourseEditor\BrowserEnvironment;
use Tests\Support\CourseEditor\EditorContextCase;
use Tests\Support\CourseEditor\EditorFixture;

beforeEach(fn () => BrowserEnvironment::assertReady());

test('each editor context is backed by fresh visible synthetic data', function (string $contextName): void {
    $context = EditorContextCase::all()[$contextName];
    $fixture = EditorFixture::create($context);

    if ($fixture->user !== null) {
        $this->actingAs($fixture->user);
    }

    if ($fixture->session !== []) {
        $this->withSession($fixture->session);
    }

    visit($fixture->url())
        ->assertNoJavascriptErrors()
        ->assertSee($fixture->token);
})->with('course editor contexts');
