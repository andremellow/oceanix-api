<?php

use Illuminate\Support\Facades\Schedule;

// Materialization is idempotent, so a missed or repeated run is harmless: it creates only
// the occurrences that are due and nothing else. See docs/product-spec.md §10.
Schedule::command('oceanix:materialize-requirements')->hourly()->withoutOverlapping();

Schedule::command('oceanix:update-overdue')->dailyAt('00:15');

// Reconcile assets whose encoding finished after the editor was closed.
Schedule::command('oceanix:sync-videos')->everyTenMinutes()->withoutOverlapping();
