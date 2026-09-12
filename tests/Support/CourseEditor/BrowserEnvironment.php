<?php

namespace Tests\Support\CourseEditor;

use RuntimeException;

final class BrowserEnvironment
{
    public static function assertReady(): void
    {
        $playwright = base_path('node_modules/.bin/playwright');

        if (! is_file($playwright) || ! is_executable($playwright)) {
            throw new RuntimeException('Playwright is missing. Run npm ci and npx playwright install chromium.');
        }

        if (! extension_loaded('sockets')) {
            throw new RuntimeException('The PHP sockets extension is required by Pest Browser.');
        }
    }

    public static function artifactPath(string $filename): string
    {
        $directory = base_path('tests/Browser/Artifacts');

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create browser artifact directory [{$directory}].");
        }

        return $directory.DIRECTORY_SEPARATOR.basename($filename);
    }
}
