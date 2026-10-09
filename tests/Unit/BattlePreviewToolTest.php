<?php

require_once __DIR__ . '/../Support/Battle/QueuedCommandFixture.php';

function runBattlePreviewTool(array $arguments, bool $acknowledgeSelection = true, bool $cleanupFails = false, ?string $input = null): array
{
    $directory = sys_get_temp_dir() . '/battle-preview-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $fixturePath = $directory . '/fixture.php';
    $fixture = <<<'PHP'
<?php
return static function (string $root, bool $reduced, ?array $subjects = null): array {
    $selected = $subjects ?? ['actor-a', 'actor-b'];
    foreach ($selected as $id) {
        if (!in_array($id, ['actor-a', 'actor-b'], true)) {
            throw new RuntimeException('Unknown fixture subject: ' . $id);
        }
    }
    $frames = 0;
    $last = -1.0;
    return [
        'subjects' => ACK_SELECTION ? $selected : ['actor-a', 'actor-b'],
        'frame' => static function (float $seconds) use (&$frames, &$last) {
            if ($seconds < $last) { throw new RuntimeException('Preview clock moved backwards.'); }
            $last = $seconds;
            $frames++;
            return new \Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas(20, 20);
        },
        'verify' => static function () use (&$frames, &$last, $root, $reduced, $selected) {
            if ($last < 1) { throw new RuntimeException('Preview verification ran too early.'); }
            return ['frameCalls' => $frames, 'lastSeconds' => $last, 'subjects' => $selected,
                'reducedMotion' => $reduced, 'assetRoot' => $root];
        },
        'dispose' => static function () {
            if (CLEANUP_FAILS) { throw new RuntimeException('Deliberate fixture cleanup failure'); }
        },
    ];
};
PHP;
    file_put_contents($fixturePath, str_replace(['ACK_SELECTION', 'CLEANUP_FAILS'],
        [$acknowledgeSelection ? 'true' : 'false', $cleanupFails ? 'true' : 'false'], $fixture));
    $process = null;
    try {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/tools/gpui-battle-preview.php',
            '--asset-root=' . $directory, '--fixture=' . $fixturePath, '--duration=1', '--no-launch', ...$arguments],
            [0 => $input === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        expect(is_resource($process))->toBeTrue();
        if ($input !== null) { fwrite($pipes[0], $input); fclose($pipes[0]); }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process); $process = null;
        expect(scandir($directory))->toBe(['.', '..', 'fixture.php']);
        return compact('exit', 'stdout', 'stderr', 'directory');
    } finally {
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        unlink($fixturePath);
        rmdir($directory);
    }
}

it('steps an inspection through every fixture frame before verifying and disposing', function () {
    $result = runBattlePreviewTool(['--inspect'], input: "step 0.4\nstep 0.6\n");
    expect($result['exit'])->toBe(0, $result['stderr'])
        ->and($result['stdout'])->toEndWith("CPU preview verification passed; no native window launched.\n");
    $json = substr($result['stdout'], 0, strrpos($result['stdout'], "\nCPU preview"));
    $evidence = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    expect($evidence['frames'])->toBeGreaterThanOrEqual(61)
        ->and($evidence['fixture']['frameCalls'])->toBe($evidence['frames'])
        ->and($evidence['fixture']['lastSeconds'])->toEqual(1.0);
});

it('does not claim inspection acceptance when its control input closes or is invalid', function (?string $input) {
    $result = runBattlePreviewTool(['--inspect'], input: $input);
    expect($result['exit'])->toBe(1)->and($result['stdout'])->toBe('');
})->with([null, "step 0.5\n", "quit\n", "step -1\n"]);

it('keeps the full default or acknowledges exactly selected preview identities without a native launch',
    function (?array $subjects, bool $reduced) {
        $arguments = $subjects === null ? [] : ['--subjects=' . implode(',', $subjects)];
        if ($reduced) { $arguments[] = '--reduced-motion'; }
        $result = runBattlePreviewTool($arguments);
        expect($result['exit'])->toBe(0, $result['stderr'])
            ->and($result['stderr'])->toBe('')
            ->and($result['stdout'])->toEndWith("CPU preview verification passed; no native window launched.\n");
        $json = substr($result['stdout'], 0, strrpos($result['stdout'], "\nCPU preview"));
        $evidence = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        expect($evidence['subjects'])->toBe($subjects ?? ['actor-a', 'actor-b'])
            ->and($evidence['nativePresentedFrames'])->toBe(0)
            ->and($evidence['reducedMotion'])->toBe($reduced)
            ->and($evidence['fixture']['subjects'])->toBe($evidence['subjects'])
            ->and($evidence['fixture']['reducedMotion'])->toBe($reduced)
            ->and($evidence['fixture']['assetRoot'])->toBe($result['directory'])
            ->and($evidence['fixture']['frameCalls'])->toBe($evidence['frames'])
            ->and($evidence['fixture']['lastSeconds'])->toBeGreaterThanOrEqual(1);
    })->with([
        'full normal' => [null, false], 'full reduced' => [null, true],
        'selected normal' => [['actor-b'], false], 'selected reduced' => [['actor-b', 'actor-a'], true],
    ]);

it('verifies real queued combat through the preview driver without a native launch', function (bool $reduced) {
    $root = sys_get_temp_dir() . '/queued-preview-driver-' . bin2hex(random_bytes(6));
    $process = null;
    try {
        \Tests\Support\Battle\writeQueuedAttackEffects($root, 8);
        foreach (['Arena', 'Idle', 'Attack', 'Damage'] as $name) {
            \Tests\Support\Rendering\writeTestPng($root . '/assets/' . $name . '.png', 48, 80);
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $before = [];
        foreach ($files as $file) { $before[$file->getPathname()] = hash_file('sha256', $file->getPathname()); }
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/tools/gpui-battle-preview.php',
            '--asset-root=' . $root . '/assets', '--fixture=' . __DIR__ . '/../Fixtures/Rendering/BattleCommandPreview.php',
            '--duration=40', '--no-launch', ...($reduced ? ['--reduced-motion'] : [])],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        expect(is_resource($process))->toBeTrue();
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process); $process = null;
        expect($exit)->toBe(0, $stderr)->and($stderr)->toBe('')
            ->and($stdout)->toEndWith("CPU preview verification passed; no native window launched.\n");
        $evidence = json_decode(substr($stdout, 0, strrpos($stdout, "\nCPU preview")), true, flags: JSON_THROW_ON_ERROR);
        expect($evidence['subjects'])->toBe(['traditional', 'atb'])
            ->and($evidence['reducedMotion'])->toBe($reduced)->and($evidence['nativePresentedFrames'])->toBe(0)
            ->and($evidence['fixture']['commands'])->toHaveCount(4)
            ->and($evidence['fixture']['sourceAndTargetCrops'])->toBe($reduced ? 1 : 8)
            ->and($evidence['fixture']['returnedToFormation'])->toBeTrue()
            ->and($evidence['fixture']['damageReactionsObserved'])->toBeTrue()
            ->and($evidence['fixture']['isolatedSilentAudio'])->toBeTrue();
        $after = [];
        foreach ($files as $file) { $after[$file->getPathname()] = hash_file('sha256', $file->getPathname()); }
        expect($after)->toBe($before);
    } finally {
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($root);
    }
})->with([false, true]);

it('refuses malformed, unknown or unacknowledged preview selections without claiming success',
    function (string $selection, bool $acknowledge, string $message) {
        $result = runBattlePreviewTool(['--subjects=' . $selection], $acknowledge);
        expect($result['exit'])->toBe(1)
            ->and($result['stderr'])->toContain($message)
            ->and($result['stdout'])->toBe('');
    })->with([
        'empty' => ['', true, 'must be non-empty, distinct and at most 64'],
        'blank member' => ['actor-a, ', true, 'must be non-empty, distinct and at most 64'],
        'duplicate' => ['actor-a,actor-a', true, 'must be non-empty, distinct and at most 64'],
        'oversized' => [implode(',', array_map(static fn(int $id): string => 'actor-' . $id, range(0, 64))),
            true, 'must be non-empty, distinct and at most 64'],
        'unknown' => ['unregistered-actor', true, 'Unknown fixture subject: unregistered-actor'],
        'ignored selection' => ['actor-b', false, 'must validate and acknowledge the exact requested subject IDs'],
    ]);

it('refuses repeated subject options instead of treating one as the accepted scope', function () {
    $result = runBattlePreviewTool(['--subjects=actor-a', '--subjects=actor-b']);
    expect($result['exit'])->toBe(1)
        ->and($result['stderr'])->toContain('must be non-empty, distinct and at most 64')
        ->and($result['stdout'])->toBe('');
});

it('runs owned fixture cleanup on success and failure and never reports success before cleanup', function (bool $badSelection) {
    $result = runBattlePreviewTool($badSelection ? ['--subjects=actor-b'] : [], !$badSelection, true);
    expect($result['exit'])->toBe(1)->and($result['stderr'])->toContain('Fixture cleanup: Deliberate fixture cleanup failure')
        ->and($result['stdout'])->toBe('');
})->with([false, true]);

it('runs the native command fixture through actual queued party and enemy actions without changing project assets or settings',
    function (?array $subjects, bool $reduced) {
        $root = sys_get_temp_dir() . '/queued-battle-preview-' . bin2hex(random_bytes(6));
        $fixture = null;
        $cwd = getcwd();
        $delta = new ReflectionProperty(\Ichiloto\Engine\Core\Time::class, 'deltaTime')->getValue();
        $config = \Ichiloto\Engine\Util\Config\ConfigStore::has(\Ichiloto\Engine\Util\Config\ProjectConfig::class)
            ? \Ichiloto\Engine\Util\Config\ConfigStore::get(\Ichiloto\Engine\Util\Config\ProjectConfig::class) : null;
        $animations = \Ichiloto\Engine\Battle\BattleCommandCatalog::getBattleAnimationLibrary();
        try {
            \Tests\Support\Battle\writeQueuedAttackEffects($root, 8);
            foreach (['Arena', 'Idle', 'Attack', 'Damage'] as $name) {
                \Tests\Support\Rendering\writeTestPng($root . '/assets/' . $name . '.png', 48, 80);
            }
            $before = [];
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) { $before[$file->getPathname()] = hash_file('sha256', $file->getPathname()); }
            $factory = require __DIR__ . '/../Fixtures/Rendering/BattleCommandPreview.php';
            $fixture = $factory($root . '/assets', $reduced, $subjects);
            for ($frame = 0; $frame <= 2400; $frame++) {
                $canvas = $fixture['frame']($frame / 60);
                expect($canvas)->toBeInstanceOf(\Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas::class);
            }
            $evidence = $fixture['verify']();
            expect($fixture['subjects'])->toBe($subjects ?? ['traditional', 'atb'])
                ->and($fixture['requiredCapabilities'])->toBe([\Ichiloto\Engine\Rendering\Transport\RendererSessionConfig::CANVAS_IMAGE_FLIP])
                ->and($evidence['sourceAndTargetCrops'])->toBe($reduced ? 1 : 8)
                ->and($evidence['damageReactionsObserved'])->toBeTrue()
                ->and($evidence['commands'])->toHaveCount(count($subjects ?? ['traditional', 'atb']) * 2);
            foreach ($evidence['commands'] as $key => $result) {
                expect($result['hits'])->toBe(str_ends_with($key, ':0') ? 2 : 1)
                    ->and($result['mp'])->toBe(46)->and($result['hp'])->toBe(str_ends_with($key, ':0') ? 80 : 70);
            }
            $after = [];
            foreach ($iterator as $file) { $after[$file->getPathname()] = hash_file('sha256', $file->getPathname()); }
            expect($after)->toBe($before)->and(getcwd())->toBe($cwd);
            $fixture['dispose']();
            $fixture['dispose']();
            expect(new ReflectionProperty(\Ichiloto\Engine\Core\Time::class, 'deltaTime')->getValue())->toBe($delta)
                ->and(\Ichiloto\Engine\Battle\BattleCommandCatalog::getBattleAnimationLibrary())->toBe($animations)
                ->and(\Ichiloto\Engine\Util\Config\ConfigStore::has(\Ichiloto\Engine\Util\Config\ProjectConfig::class)
                    ? \Ichiloto\Engine\Util\Config\ConfigStore::get(\Ichiloto\Engine\Util\Config\ProjectConfig::class) : null)->toBe($config);
        } finally {
            if ($fixture !== null) { $fixture['dispose'](); }
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
            rmdir($root);
        }
    })->with([
        'both normal' => [null, false], 'both reduced' => [null, true],
        'traditional normal' => [['traditional'], false], 'atb reduced' => [['atb'], true],
    ]);
