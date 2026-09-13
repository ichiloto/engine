#!/usr/bin/env php
<?php

use Ichiloto\Engine\IO\Console\ConsoleFrameSnapshot;
use Ichiloto\Engine\IO\InputSources\RendererInputSource;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\ProcessRendererTransport;
use Ichiloto\Engine\Rendering\Transport\RendererEventType;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

require dirname(__DIR__) . '/vendor/autoload.php';

/** Standalone fixture data, deliberately independent of Game and project assets. */
function frameSmokeSnapshot(bool $changed): ConsoleFrameSnapshot
{
  $rows = [
    $changed ? 'ICHILOTO S4 / FRAME 2' : 'ICHILOTO S4 / FRAME 1',
    '012345678901234567890123456789012345678901234567',
    '+----------+   +----------------+',
    '|mm   |   8|   |        -  -    |',
    '|     -----+   |     ] ###### [ |',
    '|          |   |        -  -    |',
    '|          |___|                |',
    $changed ? '|       @                       |' : '|                      @        |',
    '|          +---+    +------     |',
    '|          |        |           |',
    '+----------+   +----+   #####   |',
    '                    |           |',
    '                    | 0  |||  0 |',
    '                    |   _____   |',
    '                    +-----------+',
    '',
    $changed ? 'NEW FRAME: old @ and old text are gone.' : 'OLD FRAME: this line must be replaced.',
    'Native keys are data, never game actions.',
  ];
  $rows = array_map(static fn(string $row): string => str_pad($row, 48), $rows);
  return new ConsoleFrameSnapshot(48, 20, array_pad($rows, 20, str_repeat(' ', 48)));
}

function reportFrameSmokeEvents(RendererClient $client, RendererInputSource $input): bool
{
  for ($i = 0; $i < 128 && ($key = $input->poll()) !== null; $i++) {
    fwrite(STDOUT, "INPUT key={$key->value} KeyCode={$key->name}\n");
  }
  $closed = false;
  foreach ($client->pollEvents() as $event) {
    fwrite(STDOUT, 'RENDERER ' . $event->type->value
      . ($event->message !== null ? ' message=' . json_encode($event->message, JSON_THROW_ON_ERROR) : '') . "\n");
    if ($event->type === RendererEventType::ERROR) {
      throw new RuntimeException('Renderer rejected smoke data: ' . $event->message);
    }
    $closed = $closed || $event->type === RendererEventType::CLOSE_REQUESTED;
  }
  return $closed;
}

$client = null;
$result = 0;
try {
  $options = getopt('', ['renderer:', 'asset-root:', 'sprite:', 'duration:', 'change-after:', 'help']);
  if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/gpui-frame-smoke.php --renderer=/absolute/executable --asset-root=/absolute/directory\n"
      . "  [--sprite=relative/test.png] [--duration=60] [--change-after=10]\n"
      . "Inspect FRAME 1, then its timed FRAME 2 replacement. Identical frames are suppressed.\n"
      . "Enter in this terminal requests shutdown; native keys remain input data.\n");
    exit(0);
  }
  $renderer = $options['renderer'] ?? null;
  $assetRoot = $options['asset-root'] ?? null;
  $sprite = $options['sprite'] ?? null;
  $duration = filter_var($options['duration'] ?? '60', FILTER_VALIDATE_FLOAT);
  $changeAfter = filter_var($options['change-after'] ?? '10', FILTER_VALIDATE_FLOAT);
  if (! is_string($renderer) || ! str_starts_with($renderer, '/') || ! is_file($renderer) || ! is_executable($renderer)
    || ! is_string($assetRoot) || ($sprite !== null && ! is_string($sprite))
    || $duration === false || ! is_finite($duration) || $duration <= 0 || $duration > 300
    || $changeAfter === false || ! is_finite($changeAfter) || $changeAfter < 0 || $changeAfter >= $duration) {
    throw new InvalidArgumentException('Provide executable/asset-root paths, duration in (0,300], and change-after in [0,duration).');
  }
  $sprites = $sprite === null ? [] : [new PresentationSprite('calibration', $sprite, 8, 4, 32, 48, layer: 100)];
  $grid = new RendererGridConfig(48, 20);
  $transport = new ProcessRendererTransport(new RendererProcessConfig([$renderer]));
  $client = new RendererClient($transport);
  $client->start(new RendererSessionConfig('Ichiloto PHP Frame Smoke Test', $assetRoot, $grid));
  $input = new RendererInputSource($client);
  $presentation = new RendererPresentation($client, $grid);
  fwrite(STDERR, "Frame client handshake complete. Fixture-only presentation; no Game or gameplay integration.\n");
  $snapshot = frameSmokeSnapshot(false);
  $presentation->present($snapshot, $sprites);
  fwrite(STDOUT, "FRAME number=1 queued\n");
  if ($presentation->present($snapshot, $sprites)) {
    throw new RuntimeException('Identical frame was unexpectedly queued.');
  }
  fwrite(STDOUT, "FRAME duplicate suppressed\n");
  $started = hrtime(true);
  $changed = false;
  stream_set_blocking(STDIN, false);
  do {
    if (reportFrameSmokeEvents($client, $input)) {
      break;
    }
    $terminalInput = fread(STDIN, 8192);
    if ($terminalInput !== false && str_contains($terminalInput, "\n")) {
      break;
    }
    $elapsed = (hrtime(true) - $started) / 1_000_000_000;
    if (! $changed && $elapsed >= $changeAfter) {
      $presentation->present(frameSmokeSnapshot(true));
      $changed = true;
      fwrite(STDOUT, "FRAME number=2 queued (full text replacement; sprites cleared)\n");
    }
    usleep(20000);
  } while ($elapsed < $duration);
  $exitCode = $client->shutdown();
  reportFrameSmokeEvents($client, $input);
  fwrite(STDERR, "Renderer exited with status {$exitCode}; frame client cleanup complete.\n");
  if ($transport->getDiagnostics() !== '') {
    fwrite(STDERR, 'Renderer stderr tail: ' . $transport->getDiagnostics() . "\n");
  }
} catch (Throwable $error) {
  fwrite(STDERR, $error->getMessage() . "\n");
  $result = 1;
} finally {
  if ($client !== null) {
    try {
      $client->shutdown();
    } catch (Throwable $error) {
      fwrite(STDERR, 'Cleanup: ' . $error->getMessage() . "\n");
      $result = 1;
    }
  }
}
exit($result);
