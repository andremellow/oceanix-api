<?php

test('the real browser can reach Laravel and execute JavaScript at supported viewports', function () {
    $page = visit('/login')
        ->assertNoJavascriptErrors()
        ->assertScript('document.documentElement.lang.length > 0')
        ->resize(1440, 900)
        ->assertScript('window.innerWidth === 1440')
        ->resize(320, 568)
        ->assertScript('window.innerWidth === 320');

    $page->screenshot(filename: 'browser-environment-smoke');
});
