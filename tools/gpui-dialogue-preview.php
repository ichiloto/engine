#!/usr/bin/env php
<?php

declare(strict_types=1);

use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueCanvasPresentation;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationCatalog;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueSnapshot;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererEventType;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\ProcessRendererTransport;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition;

require dirname(__DIR__) . '/vendor/autoload.php';

// Renderer-only fixture: no Game, audio, saves or project source writes.
$client = null;
$result = 0;
try {
    $options = getopt('', ['renderer:', 'asset-root:', 'snapshot:', 'background:', 'width:', 'height:', 'duration:', 'help']);
    if (isset($options['help'])) {
        echo "Usage: php tools/gpui-dialogue-preview.php --renderer=PATH --asset-root=PATH --snapshot=JSON\n"
            . "  [--width=1280 --height=720 --duration=20]\n"
            . "  [--background=ASSET_ROOT_RELATIVE_PNG] Preview-only skit artwork; never writes bindings.\n"
            . "Snapshot: speaker, text, optional actorId/emotion/position/visibleText/printing/auto,\n"
            . "skitId/skitTitle/location/participants and bindings. Silent fixture; closes automatically.\n";
        exit(0);
    }
    $renderer = $options['renderer'] ?? '';
    $root = $options['asset-root'] ?? '';
    $snapshot = $options['snapshot'] ?? '';
    $width = filter_var($options['width'] ?? '1280', FILTER_VALIDATE_INT);
    $height = filter_var($options['height'] ?? '720', FILTER_VALIDATE_INT);
    $duration = filter_var($options['duration'] ?? '20', FILTER_VALIDATE_FLOAT);
    if (!is_file($renderer) || !is_executable($renderer) || !is_dir($root) || !is_file($snapshot)
        || $width === false || $width < 800 || $width > 2560 || $width % 16 !== 0
        || $height === false || $height < 480 || $height > 1440 || $height % 24 !== 0
        || $duration === false || !is_finite($duration) || $duration < 1 || $duration > 60) {
        throw new InvalidArgumentException('Provide renderer, assets and snapshot paths; bounded dimensions divisible by 16x24 and duration 1..60.');
    }
    $data = json_decode(file_get_contents($snapshot), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !is_string($data['text'] ?? null) || !is_string($data['speaker'] ?? null)) {
        throw new InvalidArgumentException('Snapshot requires speaker and text strings.');
    }
    if (isset($data['bindings'])) {
        $bindings = $data['bindings'];
        foreach ($bindings as &$binding) {
            $binding['keys'] = array_map(static fn($name) => array_find(KeyCode::cases(), fn($key) => $key->name === $name)
                ?? throw new InvalidArgumentException('Unknown KeyCode case name in preview bindings.'), $binding['keys'] ?? []);
        }
        unset($binding);
        InputManager::setBindings($bindings);
    }
    $context = new DialogueContext($data['actorId'] ?? null, $data['emotion'] ?? 'Neutral', $data['skitId'] ?? null,
        $data['skitTitle'] ?? '', $data['location'] ?? '', $data['participants'] ?? []);
    $line = new DialogueSnapshot($data['speaker'], $data['text'], $data['visibleText'] ?? $data['text'],
        $data['printing'] ?? false, 0, 1, $data['auto'] ?? false,
        isset($data['position']) ? (array_find(WindowPosition::cases(), fn($position) => $position->name === $data['position'])
            ?? throw new InvalidArgumentException('Position must be TOP, MIDDLE or BOTTOM.')) : WindowPosition::BOTTOM, $context);
    // Compose before opening a window, so invalid artwork/layout never leaves a test window behind.
    $catalogue = DialoguePresentationCatalog::load($root);
    if (isset($options['background'])) {
        if ($context->skitId === null || !is_string($options['background'])) {
            throw new InvalidArgumentException('A background preview requires a skit snapshot and an asset-root-relative PNG.');
        }
        $catalogue = new DialoguePresentationCatalog($catalogue->actors, $catalogue->theme, $catalogue->speakers,
            array_replace($catalogue->skits, [$context->skitId => ['background' => $options['background']]]),
            $catalogue->skitStage, $catalogue->resources);
        if (PngAssetPreflight::getAvailableSize($root, $options['background']) === null) {
            throw new InvalidArgumentException('The proposed background must decode within the asset root before preview.');
        }
        echo "Proposed background is preview-only. Project bindings are unchanged.\n";
    }
    $canvas = DialogueCanvasPresentation::compose($line, $catalogue, $width, $height);
    $grid = new RendererGridConfig(intdiv($width, 16), intdiv($height, 24));
    $transport = new ProcessRendererTransport(new RendererProcessConfig([$renderer]));
    $client = new RendererClient($transport);
    $client->start(new RendererSessionConfig('Ichiloto dialogue preview (silent)', $root, $grid,
        protocol: RendererProtocolVersion::V2,
        requiredCapabilities: ['graphical_canvas', 'canvas_overlay', 'canvas_clip_opacity', 'sprite_source_rect',
            ...($context->skitId === null ? [] : ['canvas_image_tone'])]));
    $client->pump();
    $presentation = new RendererPresentation($client, $grid);
    if ($context->skitId === null) {
        $field = new ConsolePresentationSnapshot($grid->columns, $grid->rows,
            [new PresentationTextLayer('fixture-field', 0, [new PresentationTextRun(2, 2,
                'Silent renderer fixture. No game/audio.')])]);
        $presentation->present($field, canvasOverlay: $canvas);
    } else {
        $presentation->presentCanvas($canvas);
    }
    $deadline = microtime(true) + $duration;
    $painted = false;
    while (microtime(true) < $deadline) {
        foreach ($client->pollEvents() as $event) {
            if (in_array($event->type, [RendererEventType::ERROR, RendererEventType::FRAME_REJECTED], true)) {
                throw new RuntimeException('Renderer rejected preview: ' . $event->message);
            }
            if ($event->type === RendererEventType::FRAME_ACK) {
                $presentation->acknowledge($event->generation, $event->presented);
                if ($event->presented && !$painted) { echo "Preview acknowledged; silent window closes automatically.\n"; $painted = true; }
            }
            if ($event->type === RendererEventType::CLOSE_REQUESTED) { break 2; }
        }
        usleep(10000);
    }
    if (!$painted) { throw new RuntimeException('No presented frame acknowledgement received.'); }
    $code = $client->shutdown();
    if ($code !== 0) { throw new RuntimeException('Renderer exited with status ' . var_export($code, true)); }
    echo "Preview closed cleanly. Native acknowledgement is not a screenshot or GPU timing measurement.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    $result = 1;
} finally {
    try { $client?->shutdown(); }
    catch (Throwable $error) { fwrite(STDERR, 'Cleanup: ' . $error->getMessage() . "\n"); $result = 1; }
}
exit($result);
