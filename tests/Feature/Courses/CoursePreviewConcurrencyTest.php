<?php

use Symfony\Component\Process\Process;

it('serializes independent PostgreSQL generation and lifecycle transactions', function (string $firstMode, string $secondMode) {
    if (getenv('PREVIEW_PG_TEST') !== '1') {
        $this->markTestSkipped('Run with PREVIEW_PG_TEST=1 against disposable loopback PostgreSQL port 55439.');
    }
    $script = base_path('tests/Support/preview-concurrency.php');
    $setup = new Process([PHP_BINARY, $script, 'setup']);
    $setup->mustRun();
    expect(json_decode($setup->getOutput(), true))->toHaveKeys(['company', 'version', 'user']);
    $fixture = tempnam(sys_get_temp_dir(), 'preview-fixture-');
    file_put_contents($fixture, $setup->getOutput());
    $barrier = $fixture.'-go';
    $a = new Process([PHP_BINARY, $script, 'hold', $fixture, $barrier, $firstMode]);
    $b = new Process([PHP_BINARY, $script, 'compete', $fixture, $barrier, $secondMode]);
    try {
        $a->start();
        $deadline = microtime(true) + 10;
        while (! file_exists($barrier.'.held') && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(file_exists($barrier.'.held'))->toBeTrue($a->getOutput());
        $b->start();
        $blocked = false;
        while (microtime(true) < $deadline && ! $blocked) {
            $observer = new Process([PHP_BINARY, $script, 'observe', $fixture, $barrier]);
            $observer->mustRun();
            $blocked = json_decode($observer->getOutput(), true)['blocked_on_course'] ?? false;
            if (! $blocked) {
                usleep(10000);
            }
        }
        expect($blocked)->toBeTrue('The competing real Action must wait on the courses FOR UPDATE lock.');
        expect($b->isRunning())->toBeTrue();
        expect($b->getOutput())->toBe('');
        touch($barrier.'.release');
        $a->wait();
        $b->wait();
        expect($a->isSuccessful())->toBeTrue($a->getErrorOutput());
        expect($b->isSuccessful())->toBeTrue($b->getErrorOutput());
        $first = json_decode($a->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $second = json_decode($b->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $inspect = new Process([PHP_BINARY, $script, 'inspect', $fixture]);
        $inspect->mustRun();
        $state = json_decode($inspect->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        if ($firstMode === 'generate' && $secondMode === 'generate') {
            expect($first['digest'])->toBe($second['digest']);
            expect($state)->toMatchArray(['count' => 1, 'status' => 'draft', 'read' => 200]);
        } else {
            expect(($firstMode === 'publish' ? $first : $second)['published'])->toBeTrue();
            if ($firstMode === 'publish') {
                expect($second['status'])->toBe(409);
            }
            expect($state['status'])->toBe('published');
            expect($state['count'])->toBeLessThanOrEqual(1);
            expect($state['read'])->toBeIn([null, 410]);
            if (isset($first['status'])) {
                expect($first['status'])->toBe(409);
            }
        }
    } finally {
        $a->stop();
        $b->stop();
        foreach (glob($barrier.'*') as $file) {
            unlink($file);
        }
        unlink($fixture);
    }
})->with(['generation pair' => ['generate', 'generate'], 'generation before publication' => ['generate', 'publish'], 'publication before generation' => ['publish', 'generate']]);
