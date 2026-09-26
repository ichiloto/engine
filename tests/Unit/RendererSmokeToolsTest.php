<?php

use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererMessageType;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererMessage;
use Tests\Support\Rendering\RetainedFrameState;

require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';

it('runs native smoke entry points on protocol two using a silent PHP pipe peer', function (string $tool, bool $recover) {
  $directory = sys_get_temp_dir() . '/retained-smoke-' . bin2hex(random_bytes(6));
  mkdir($directory);
  $executable = $directory . '/renderer'; $capture = $directory . '/wire.ndjson';
  $stub = __DIR__ . '/../Fixtures/Renderer/renderer-stub.php';
  file_put_contents($executable, '#!' . PHP_BINARY . "\n<?php\n\$argv = "
    . var_export([$executable, $recover ? 'retained_smoke_recovery' : 'retained_smoke', $capture], true) . ";\nrequire " . var_export($stub, true) . ";\n");
  chmod($executable, 0755);
  $process = null;
  try {
    $command = [PHP_BINARY, dirname(__DIR__, 2) . '/tools/' . $tool,
      '--renderer=' . $executable, '--asset-root=' . $directory];
    // The healthy frame run remains unchanged beyond the ACK timeout, without a spurious reset.
    $command[] = $tool === 'gpui-frame-smoke.php' ? ($recover ? '--duration=0.3' : '--duration=2.5') : '--duration=0';
    if ($tool === 'gpui-frame-smoke.php') { $command[] = '--change-after=0.15'; $command[] = '--sprite=synthetic.png'; }
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect(is_resource($process))->toBeTrue();
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process); $process = null;
    expect($exit)->toBe(0, $stderr);
    $wire = array_map(fn($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($capture, FILE_IGNORE_NEW_LINES));
    expect(array_unique(array_column($wire, 'protocol')))->toBe([2])
      ->and($wire[0]['type'])->toBe('hello')->and(end($wire)['type'])->toBe('shutdown');
    if ($tool === 'gpui-frame-smoke.php') {
      $frames = array_values(array_filter($wire, fn($message) => $message['type'] === 'frame'));
      expect($frames[0]['reset'])->toBeTrue()->and($stdout)->toContain('FRAME duplicate suppressed');
      if ($recover) {
        // Rejection can arrive before or after the timed change; either way one resync suffices.
        expect(count(array_filter(array_column($frames, 'reset'))))->toBe(2)
          ->and($stdout)->toContain('frame_rejected', 'resized');
      } else {
        expect($frames)->toHaveCount(2)->and(array_column($frames, 'reset'))->toBe([true, false])
          ->and(array_column($frames[1]['operations'], 'op'))->toContain('textRows', 'remove')->not->toContain('put');
      }
      $messages = [];
      foreach ($frames as $frame) {
        expect($frame)->toHaveKey('operations')->not->toHaveKeys(['text', 'textLayers', 'tileBatches', 'sprites']);
        unset($frame['protocol'], $frame['type']);
        $messages[] = new RendererMessage(RendererMessageType::FRAME, $frame, RendererProtocolVersion::V2);
      }
      $state = RetainedFrameState::replay($messages);
      expect($state[0]['sprites'][0]['asset'])->toBe('synthetic.png')
        ->and(end($state)['sprites'])->toBe([])
        ->and(end($state)['textLayers'][0]['runs'][0]['text'])->toStartWith('ICHILOTO S4 / FRAME 2');
    } else {
      expect(array_column($wire, 'type'))->toBe(['hello', 'shutdown']);
    }
  } finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    foreach (glob($directory . '/*') as $file) { unlink($file); }
    rmdir($directory);
  }
})->with([
  ['gpui-transport-smoke.php', false], ['gpui-input-smoke.php', false],
  ['gpui-frame-smoke.php', false], ['gpui-frame-smoke.php', true],
]);
