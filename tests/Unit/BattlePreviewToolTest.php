<?php

function runBattlePreviewTool(array $arguments, bool $acknowledgeSelection = true): array
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
    ];
};
PHP;
    file_put_contents($fixturePath, str_replace('ACK_SELECTION', $acknowledgeSelection ? 'true' : 'false', $fixture));
    $process = null;
    try {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/tools/gpui-battle-preview.php',
            '--asset-root=' . $directory, '--fixture=' . $fixturePath, '--duration=1', '--no-launch', ...$arguments],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        expect(is_resource($process))->toBeTrue();
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
