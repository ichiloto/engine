#!/usr/bin/env php
<?php

use Ichiloto\Engine\Rendering\Transport\ProcessRendererTransport;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererEventType;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

require dirname(__DIR__) . '/vendor/autoload.php';

/** Print PHP-parsed events, never the child's raw pipes. */
function reportRendererEvent(RendererEvent $event): void
{
  $data = ['protocol' => $event->protocol->value, 'type' => $event->type->value];
  if ($event->key !== null) {
    $data['key'] = $event->key;
  }
  if ($event->message !== null) {
    $data['message'] = $event->message;
  }
  fwrite(STDOUT, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
}

$transport = null;
$result = 0;
try {
  $options = getopt('', ['renderer:', 'asset-root:', 'duration:', 'help']);
  if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/gpui-transport-smoke.php --renderer=/absolute/executable --asset-root=/absolute/directory [--duration=60]\n"
      . "Default: handshake then shutdown. With duration (0-300 seconds), inspect native keys and close.\n"
      . "Press Enter in this terminal to request protocol shutdown. Native keys never quit PHP.\n");
    exit(0);
  }
  $renderer = $options['renderer'] ?? null;
  $assetRoot = $options['asset-root'] ?? null;
  $duration = filter_var($options['duration'] ?? '0', FILTER_VALIDATE_FLOAT);
  if (! is_string($renderer) || ! str_starts_with($renderer, '/') || ! is_file($renderer) || ! is_executable($renderer)
    || ! is_string($assetRoot) || $duration === false || ! is_finite($duration) || $duration < 0 || $duration > 300) {
    throw new InvalidArgumentException('Provide --renderer=/absolute/executable, --asset-root=/absolute/directory, and optionally --duration=0..300.');
  }
  $transport = new ProcessRendererTransport(new RendererProcessConfig([$renderer]));
  $transport->start(new RendererSessionConfig('Ichiloto PHP Transport Smoke Test', $assetRoot));
  fwrite(STDERR, "PHP hello/ready handshake complete. No frame or gameplay integration.\n");
  $deadline = hrtime(true) + (int) ($duration * 1_000_000_000);
  stream_set_blocking(STDIN, false);
  do {
    $closed = false;
    foreach ($transport->pollEvents(0.02) as $event) {
      reportRendererEvent($event);
      $closed = $closed || $event->type === RendererEventType::CLOSE_REQUESTED;
    }
    $terminalInput = fread(STDIN, 8192);
    if ($closed || ($terminalInput !== false && str_contains($terminalInput, "\n"))) {
      break;
    }
  } while (hrtime(true) < $deadline);

  $exitCode = $transport->shutdown();
  foreach ($transport->pollEvents() as $event) {
    reportRendererEvent($event);
  }
  fwrite(STDERR, "Renderer exited with status {$exitCode}; process and pipes released.\n");
  if ($transport->getDiagnostics() !== '') {
    fwrite(STDERR, "Renderer stderr tail:\n" . $transport->getDiagnostics() . "\n");
  }
} catch (Throwable $error) {
  fwrite(STDERR, $error->getMessage() . "\n");
  $result = 1;
} finally {
  if ($transport !== null) {
    try {
      $transport->shutdown();
    } catch (Throwable $error) {
      fwrite(STDERR, 'Cleanup: ' . $error->getMessage() . "\n");
      $result = 1;
    }
  }
}
exit($result);
