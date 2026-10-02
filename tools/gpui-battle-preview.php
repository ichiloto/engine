#!/usr/bin/env php
<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererEventType;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\ProcessRendererTransport;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

require dirname(__DIR__) . '/vendor/autoload.php';

// The trusted local fixture owns its isolated state. This driver has no Game or audio lifecycle.
$client = null;
$result = 0;
try {
    $options = getopt('', ['renderer:', 'asset-root:', 'fixture:', 'subjects:', 'duration:', 'no-launch', 'reduced-motion', 'help']);
    if (isset($options['help'])) {
        echo "Usage: php tools/gpui-battle-preview.php --asset-root=PATH --fixture=PHP [--renderer=PATH]\n"
            . "  [--subjects=ID,ID --duration=12 --reduced-motion --no-launch]\n"
            . "The trusted factory accepts root, reducedMotion and optional subject IDs, then returns frame/verify callbacks.\n"
            . "Without --subjects the fixture keeps its complete default; explicit selections must be acknowledged.\n"
            . "Frames use the production battle presenter. Silent, bounded, no project writes; closes automatically.\n";
        exit(0);
    }
    $root = $options['asset-root'] ?? '';
    $fixturePath = $options['fixture'] ?? '';
    $renderer = $options['renderer'] ?? '';
    $duration = filter_var($options['duration'] ?? '12', FILTER_VALIDATE_FLOAT);
    $noLaunch = isset($options['no-launch']);
    // getopt drops empty values; an explicit empty selection must not become the full default.
    $subjectOptionProvided = array_any($argv, static fn(string $argument): bool => $argument === '--subjects'
        || str_starts_with($argument, '--subjects='));
    if ($subjectOptionProvided && (!isset($options['subjects']) || !is_string($options['subjects']))) {
        throw new InvalidArgumentException('Preview subject IDs must be non-empty, distinct and at most 64.');
    }
    $subjects = isset($options['subjects']) ? array_map(trim(...), explode(',', $options['subjects'])) : null;
    if ($subjects !== null && (count($subjects) > 64 || in_array('', $subjects, true)
        || count(array_unique($subjects)) !== count($subjects))) {
        throw new InvalidArgumentException('Preview subject IDs must be non-empty, distinct and at most 64.');
    }
    if (!is_dir($root) || !is_file($fixturePath) || $duration === false || !is_finite($duration)
        || $duration < 1 || $duration > 60 || (!$noLaunch && (!is_file($renderer) || !is_executable($renderer)))) {
        throw new InvalidArgumentException('Provide readable assets/fixture paths, an executable renderer and duration 1..60.');
    }
    $factory = (static fn(string $path): mixed => require $path)($fixturePath);
    if (!is_callable($factory)) { throw new InvalidArgumentException('Battle preview fixture must return a factory.'); }
    $fixture = $factory($root, isset($options['reduced-motion']), $subjects);
    if (!is_array($fixture) || !is_callable($fixture['frame'] ?? null) || !is_callable($fixture['verify'] ?? null)) {
        throw new InvalidArgumentException('Battle preview fixture requires frame and verify callbacks.');
    }
    if ($subjects !== null && ($fixture['subjects'] ?? null) !== $subjects) {
        throw new InvalidArgumentException('The fixture must validate and acknowledge the exact requested subject IDs.');
    }
    $first = $fixture['frame'](0.0);
    if (!$first instanceof PresentationCanvas || $first->width % 10 !== 0 || $first->height % 20 !== 0) {
        throw new InvalidArgumentException('Battle preview requires canvas dimensions divisible by 10x20.');
    }
    $grid = new RendererGridConfig(intdiv($first->width, 10), intdiv($first->height, 20), 10, 20);
    if (!$noLaunch) {
        $client = new RendererClient(new ProcessRendererTransport(new RendererProcessConfig([$renderer])));
        $client->start(new RendererSessionConfig('Ichiloto battle command preview (silent)', $root, $grid,
            protocol: RendererProtocolVersion::V2, requiredCapabilities: [RendererSessionConfig::GRAPHICAL_CANVAS,
                RendererSessionConfig::SPRITE_SOURCE_RECT, RendererSessionConfig::CANVAS_CLIP_OPACITY,
                RendererSessionConfig::CANVAS_COMPOSITING]));
        $client->pump();
        $presentation = new RendererPresentation($client, $grid);
    }
    $acknowledgements = $frames = 0;
    $started = hrtime(true) / 1_000_000_000;
    $seconds = 0.0;
    while ($seconds <= $duration) {
        $canvas = $frames === 0 ? $first : $fixture['frame']($seconds);
        if (!$canvas instanceof PresentationCanvas || $canvas->width !== $first->width || $canvas->height !== $first->height) {
            throw new RuntimeException('Battle preview frame geometry changed.');
        }
        if (!$noLaunch) {
            $presentation->presentCanvas($canvas);
            foreach ($client->pollEvents() as $event) {
                if (in_array($event->type, [RendererEventType::ERROR, RendererEventType::FRAME_REJECTED], true)) {
                    throw new RuntimeException('Renderer rejected battle preview: ' . $event->message);
                }
                if ($event->type === RendererEventType::FRAME_ACK) {
                    $presentation->acknowledge($event->generation, $event->presented);
                    if ($event->presented) { $acknowledgements++; }
                }
                if ($event->type === RendererEventType::CLOSE_REQUESTED) {
                    throw new RuntimeException('Battle preview closed before verification.');
                }
            }
            usleep(16667);
        }
        $frames++;
        $seconds = $noLaunch ? $frames / 60 : hrtime(true) / 1_000_000_000 - $started;
    }
    $evidence = $fixture['verify']();
    if (!$noLaunch && $acknowledgements === 0) { throw new RuntimeException('No presented frame acknowledgement received.'); }
    if ($client !== null && $client->shutdown() !== 0) { throw new RuntimeException('Battle preview renderer did not exit cleanly.'); }
    echo json_encode(['frames' => $frames, 'nativePresentedFrames' => $acknowledgements,
        'reducedMotion' => isset($options['reduced-motion']), 'subjects' => $fixture['subjects'] ?? null,
        'fixture' => $evidence], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
    echo $noLaunch ? "CPU preview verification passed; no native window launched.\n"
        : "Silent preview closed cleanly. Native acknowledgements are not visual acceptance or GPU timing.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    $result = 1;
} finally {
    try { $client?->shutdown(); }
    catch (Throwable $error) { fwrite(STDERR, 'Cleanup: ' . $error->getMessage() . "\n"); $result = 1; }
}
exit($result);
