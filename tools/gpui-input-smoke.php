#!/usr/bin/env php
<?php

use Ichiloto\Engine\IO\InputSources\RendererInputSource;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\ProcessRendererTransport;
use Ichiloto\Engine\Rendering\Transport\RendererEventType;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

require dirname(__DIR__) . '/vendor/autoload.php';

function reportRendererInput(RendererClient $client, RendererInputSource $source): bool
{
  for ($count = 0; $count < 128 && ($key = $source->poll()) !== null; $count++) {
    fwrite(STDOUT, "INPUT key={$key->value} KeyCode={$key->name}\n");
  }
  $closed = false;
  foreach ($client->pollEvents() as $event) {
    fwrite(STDOUT, 'RENDERER ' . $event->type->value
      . ($event->message !== null ? ' message=' . json_encode($event->message, JSON_THROW_ON_ERROR) : '') . "\n");
    $closed = $closed || $event->type === RendererEventType::CLOSE_REQUESTED;
  }
  return $closed;
}

$client = null;
$result = 0;
try {
  $options = getopt('', ['renderer:', 'asset-root:', 'duration:', 'help']);
  if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/gpui-input-smoke.php --renderer=/absolute/executable --asset-root=/absolute/directory [--duration=60]\n"
      . "Press keys in the native window to inspect Ichiloto KeyCode normalization.\n"
      . "Enter in this terminal requests shutdown; native Enter/q/Escape are only keys.\n");
    exit(0);
  }
  $renderer = $options['renderer'] ?? null;
  $assetRoot = $options['asset-root'] ?? null;
  $duration = filter_var($options['duration'] ?? '60', FILTER_VALIDATE_FLOAT);
  if (! is_string($renderer) || ! str_starts_with($renderer, '/') || ! is_file($renderer) || ! is_executable($renderer)
    || ! is_string($assetRoot) || $duration === false || ! is_finite($duration) || $duration < 0 || $duration > 300) {
    throw new InvalidArgumentException('Provide --renderer=/absolute/executable, --asset-root=/absolute/directory, and optionally --duration=0..300.');
  }
  $transport = new ProcessRendererTransport(new RendererProcessConfig([$renderer]));
  $client = new RendererClient($transport);
  $client->start(new RendererSessionConfig('Ichiloto PHP Input Smoke Test', $assetRoot));
  $source = new RendererInputSource($client);
  fwrite(STDERR, "Input client handshake complete. No gameplay actions or frame generation.\n");
  $deadline = hrtime(true) + (int) ($duration * 1_000_000_000);
  stream_set_blocking(STDIN, false);
  do {
    if (reportRendererInput($client, $source)) {
      break;
    }
    $terminalInput = fread(STDIN, 8192);
    if ($terminalInput !== false && str_contains($terminalInput, "\n")) {
      break;
    }
    // Cadence belongs to this developer tool, never the input source or renderer.
    usleep(20000);
  } while (hrtime(true) < $deadline);
  $exitCode = $client->shutdown();
  reportRendererInput($client, $source);
  fwrite(STDERR, "Renderer exited with status {$exitCode}; client cleanup complete.\n");
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
