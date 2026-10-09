<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererMessageType;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererMessage;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Tests\Support\Rendering\RetainedFrameState;

require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';

function runScenePreviewTool(array $arguments = [], ?string $peerScenario = null,
    string $fixtureName = 'ScenePreview.php', ?string $startupInput = null, bool $closeStartupInput = true): array
{
    $directory = sys_get_temp_dir() . '/scene-preview-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $process = null;
    try {
        $mode = ['--no-launch'];
        $fixturePath = __DIR__ . '/../Fixtures/Rendering/' . $fixtureName;
        if ($peerScenario !== null) {
            $peer = $directory . '/peer.php';
            $stub = var_export(__DIR__ . '/../Fixtures/Renderer/renderer-stub.php', true);
            file_put_contents($peer, "#!/usr/bin/env php\n<?php\n\$argv = [__FILE__, "
                . var_export($peerScenario, true) . ", __DIR__ . '/wire.jsonl'];\nrequire $stub;\n");
            chmod($peer, 0700);
            $mode = ['--renderer=' . $peer];
            if (!in_array($peerScenario, ['dialogue_preview', 'scene_preview_start_key'], true)) {
                $factory = var_export($fixturePath, true);
                $fixturePath = $directory . '/fixture.php';
                file_put_contents($fixturePath, "<?php\n\$factory = require $factory;\n"
                    . "return static fn(...\$args): array => [...\$factory(...\$args), 'requiredCapabilities' => []];\n");
            }
        }
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/tools/gpui-scene-preview.php',
            '--asset-root=' . $directory, '--fixture=' . $fixturePath,
            '--duration=1', ...$mode, ...$arguments],
            [0 => $startupInput === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'],
                1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        expect(is_resource($process))->toBeTrue();
        if ($startupInput !== null) {
            fwrite($pipes[0], $startupInput);
            if ($closeStartupInput) { fclose($pipes[0]); }
        }
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (isset($pipes[0]) && is_resource($pipes[0])) { fclose($pipes[0]); }
        $exit = proc_close($process); $process = null;
        $cleanup = is_file($directory . '/cleanup.json')
            ? json_decode(file_get_contents($directory . '/cleanup.json'), true, flags: JSON_THROW_ON_ERROR) : null;
        $wire = is_file($directory . '/wire.jsonl')
            ? array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($directory . '/wire.jsonl', FILE_IGNORE_NEW_LINES)) : [];
        return compact('exit', 'stdout', 'stderr', 'cleanup', 'wire');
    } finally {
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        foreach (glob($directory . '/*') as $file) { unlink($file); }
        rmdir($directory);
    }
}

function getScenePreviewEvidence(array $result): array
{
    $end = strpos($result['stdout'], "\nCPU scene");
    if ($end === false) { $end = strpos($result['stdout'], "\nSilent scene"); }
    expect($end)->not->toBeFalse();
    return json_decode(substr($result['stdout'], 0, $end), true, flags: JSON_THROW_ON_ERROR);
}

it('drives scenes without requiring a field canvas and stops early after verified completion', function (bool $reduced) {
    $result = runScenePreviewTool($reduced ? ['--reduced-motion', '--subjects=field'] : []);
    expect($result['exit'])->toBe(0, $result['stderr'])->and($result['stderr'])->toBe('')
        ->and($result['stdout'])->toEndWith("CPU scene verification passed; no native window launched.\n");
    $evidence = getScenePreviewEvidence($result);
    expect($evidence['frames'])->toBe(10)->and($evidence['nativePresentationChanges'])->toBe(0)
        ->and($evidence['elapsedSeconds'])->toBe(0.15)->and($evidence['reducedMotion'])->toBe($reduced)
        ->and($evidence['subjects'])->toBe(['field'])->and($evidence['fixture']['renders'])->toBe(10)
        ->and($evidence['fixture']['advances'])->toBe(10)->and($evidence['fixture']['returnedFromCanvas'])->toBeTrue()
        ->and($evidence['fixture']['startedAfterRuntime'])->toBeFalse()
        ->and($result['cleanup']['calls'][0])->toBe('start')->and($result['cleanup']['stops'])->toBe(1)
        ->and($result['wire'])->toBe([]);
})->with([false, true]);

it('paces wall-clock fixtures on the CPU instead of advancing virtual time without elapsed frames', function () {
    $started = microtime(true);
    $result = runScenePreviewTool(['--subjects=realtime']);
    expect($result['exit'])->toBe(0, $result['stderr'])->and($result['stderr'])->toBe('');
    $evidence = getScenePreviewEvidence($result);
    expect($evidence['realtime'])->toBeTrue()->and($evidence['elapsedSeconds'])->toBeGreaterThanOrEqual(0.15)
        ->and(microtime(true) - $started)->toBeGreaterThanOrEqual(0.15)
        ->and($evidence['nativePresentationChanges'])->toBe(0)->and($result['wire'])->toBe([]);
});

it('holds startup until explicitly started without changing scene frame cadence', function (bool $native) {
    $result = runScenePreviewTool(['--wait-start'], $native ? 'dialogue_preview' : null, startupInput: "start\n");
    expect($result['exit'])->toBe(0, $result['stderr'])
        ->and($result['stderr'])->toContain('Waiting before scene startup');
    $evidence = getScenePreviewEvidence($result);
    expect($evidence['fixture']['advances'])->toBe($evidence['frames'])
        ->and($evidence['fixture']['lastSeconds'])->toBeGreaterThanOrEqual(0.15)
        ->and($result['cleanup']['calls'][0])->toBe('start')->and($result['cleanup']['stops'])->toBe(1);
    if (!$native) { expect($evidence['elapsedSeconds'])->toBe(0.15)->and($evidence['frames'])->toBe(10); }
})->with([false, true]);

it('refuses a closed or invalid startup barrier without starting gameplay', function (?string $input, string $message) {
    $result = runScenePreviewTool(['--wait-start'], startupInput: $input);
    expect($result['exit'])->toBe(1)->and($result['stdout'])->toBe('')
        ->and($result['stderr'])->toContain($message)->and($result['cleanup']['calls'])->toBe([]);
})->with([
    [null, 'Scene startup input closed before start'],
    ["play\n", 'Scene startup accepts only start'],
]);

it('starts from the owned native peer Enter key while stdin stays idle', function () {
    $result = runScenePreviewTool(['--wait-start'], 'scene_preview_start_key', startupInput: '', closeStartupInput: false);
    expect($result['exit'])->toBe(0, $result['stderr']);
    $evidence = getScenePreviewEvidence($result);
    expect($evidence['fixture']['startedAfterRuntime'])->toBeTrue()
        ->and($result['cleanup']['calls'][0])->toBe('start')
        ->and($result['wire'][array_key_last($result['wire'])]['type'])->toBe('shutdown');
});

it('closes the owned peer without starting gameplay when its window closes during the startup hold', function () {
    $result = runScenePreviewTool(['--wait-start'], 'close_wait', startupInput: '', closeStartupInput: false);
    expect($result['exit'])->toBe(1)->and($result['stdout'])->toBe('')
        ->and($result['stderr'])->toContain('Renderer window closed')
        ->and($result['cleanup']['calls'])->toBe([])
        ->and($result['cleanup']['stops'])->toBe(0);
});

it('passes an explicit isolated transition policy to the fixture', function (string $mode) {
    $result = runScenePreviewTool(['--transitions=' . $mode]);
    expect($result['exit'])->toBe(0, $result['stderr'])->and($result['stderr'])->toBe('');
    $evidence = getScenePreviewEvidence($result);
    expect($evidence['transitionMode'])->toBe($mode)
        ->and($evidence['fixture']['transitionMode'])->toBe($mode);
})->with(['project', 'on', 'off']);

it('rejects invalid or unacknowledged transition policies before starting a scene or renderer', function (array $arguments, string $message) {
    $result = runScenePreviewTool($arguments, 'dialogue_preview');
    expect($result['exit'])->toBe(1)->and($result['stdout'])->toBe('')
        ->and($result['stderr'])->toContain($message)->and($result['wire'])->toBe([]);
    if ($result['cleanup'] !== null) { expect($result['cleanup']['calls'])->toBe([]); }
})->with([
    [['--transitions=invalid'], 'Transition mode must be project, on or off'],
    [['--transitions'], 'Transition mode must be project, on or off'],
    [['--transitions=on', '--subjects=ignore-transitions'], 'fixture must acknowledge the exact requested transition mode'],
]);

it('attaches the production runtime before startup and submits retained field, canvas and returned field', function () {
    $result = runScenePreviewTool([], 'dialogue_preview');
    expect($result['exit'])->toBe(0, $result['stderr'])->and($result['stderr'])->toBe('');
    $evidence = getScenePreviewEvidence($result);
    expect($evidence['fixture']['startedAfterRuntime'])->toBeTrue()
        ->and($evidence['nativePresentationChanges'])->toBeGreaterThanOrEqual(3)
        ->and($result['wire'][0]['type'])->toBe('hello')
        ->and($result['wire'][0]['grid'])->toBe(['columns' => 12, 'rows' => 4, 'cellWidth' => 10, 'cellHeight' => 20])
        ->and($result['wire'][array_key_last($result['wire'])]['type'])->toBe('shutdown');
    $messages = array_map(static fn(array $message): RendererMessage => new RendererMessage(RendererMessageType::FRAME,
        array_diff_key($message, ['protocol' => 0, 'type' => 0]), RendererProtocolVersion::V2),
        array_values(array_filter($result['wire'], static fn(array $message): bool => $message['type'] === 'frame')));
    $frames = RetainedFrameState::replay($messages);
    expect(array_key_exists('canvas', $frames[0]))->toBeFalse()
        ->and(array_any($frames, static fn(array $frame): bool => array_key_exists('canvas', $frame)))->toBeTrue()
        ->and(array_key_exists('canvas', $frames[array_key_last($frames)]))->toBeFalse()
        ->and($frames[array_key_last($frames)]['textLayers'][0]['runs'][0]['text'])->toContain('FIELD BASE')
        ->and($result['cleanup']['stops'])->toBe(1);
});

it('refuses unsafe audio before a process starts and disposes isolated state', function () {
    $result = runScenePreviewTool(['--subjects=unmuted'], 'dialogue_preview');
    expect($result['exit'])->toBe(1)->and($result['stdout'])->toBe('')
        ->and($result['stderr'])->toContain('requires muted music, sound effects and master volume before launch')
        ->and($result['wire'])->toBe([])->and($result['cleanup']['calls'])->toBe([]);
});

it('starts the native session with the viewport resolved after graphical attachment', function () {
    $cpu = runScenePreviewTool(fixtureName: 'ScenePreviewAutoSize.php');
    expect($cpu['exit'])->toBe(0, $cpu['stderr'])->and($cpu['wire'])->toBe([]);
    $result = runScenePreviewTool([], 'dialogue_preview', 'ScenePreviewAutoSize.php');
    expect($result['exit'])->toBe(0, $result['stderr'])->and($result['stderr'])->toBe('')
        ->and($result['wire'][0]['grid'])->toBe(['columns' => BattleScreen::WIDTH,
            'rows' => BattleScreen::HEIGHT, 'cellWidth' => 10, 'cellHeight' => 20]);
    $evidence = getScenePreviewEvidence($result);
    expect($evidence['fixture']['startedAfterRuntime'])->toBeTrue()
        ->and($evidence['fixture']['returnedFromCanvas'])->toBeTrue()
        ->and($result['cleanup']['stops'])->toBe(1)
        ->and($result['wire'][array_key_last($result['wire'])]['type'])->toBe('shutdown');
});

it('never claims acceptance after a clock, completion, verification or cleanup failure', function (string $scenario, string $message) {
    $result = runScenePreviewTool(['--subjects=' . $scenario]);
    expect($result['exit'])->toBe(1)->and($result['stdout'])->toBe('')->and($result['stderr'])->toContain($message)
        ->and($result['cleanup'])->not->toBeNull();
})->with([
    ['never-complete', 'did not complete within its bounded duration'],
    ['invalid-completion', 'completion must be a boolean'],
    ['unmute-start', 'requires muted music'], ['unmute-advance', 'requires muted music'],
    ['verify-error', 'Deliberate verification failure'], ['cleanup-error', 'Deliberate cleanup failure'],
]);

it('shuts down its owned peer when the window closes before completion', function () {
    $result = runScenePreviewTool([], 'close_wait');
    expect($result['exit'])->toBe(1)->and($result['stdout'])->toBe('')->and($result['stderr'])->toContain('Renderer window closed')
        ->and($result['cleanup']['stops'])->toBe(1);
});

it('refuses empty, duplicate, unknown selections and invalid duration bounds', function (array $arguments, string $message) {
    $result = runScenePreviewTool($arguments);
    expect($result['exit'])->toBe(1)->and($result['stdout'])->toBe('')->and($result['stderr'])->toContain($message);
})->with([
    [['--subjects='], 'must be non-empty'], [['--subjects=field,field'], 'must be non-empty'],
    [['--subjects=unknown'], 'Unknown scene preview subject'],
    [['--duration=0'], 'duration 1..300'], [['--duration=301'], 'duration 1..300'],
]);
