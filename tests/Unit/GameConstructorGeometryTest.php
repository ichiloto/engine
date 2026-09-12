<?php

it('resolves real Game constructor geometry without mistaking injected defaults for caller intent', function (array $arguments, array $expected, array $scenario = []) {
  $root = sys_get_temp_dir() . '/ichiloto-game-constructor-' . uniqid();
  mkdir($root . '/assets/Data', 0777, true);
  file_put_contents($root . '/ichiloto.json', '{"id":"constructor-geometry-fixture"}');
  file_put_contents($root . '/assets/Data/save-compatibility.php', '<?php return ["contentVersion" => 0];');
  foreach (['config.php', 'input.php', 'assets/Data/system.php', 'assets/Data/items.php', 'assets/Data/enemies.php'] as $path) {
    file_put_contents($root . '/' . $path, '<?php return [];');
  }

  try {
    // A fresh process isolates Game's real global handlers and singletons.
    $process = proc_open([
      PHP_BINARY,
      __DIR__ . '/../Fixtures/Renderer/game-constructor.php', json_encode($arguments, JSON_THROW_ON_ERROR),
      json_encode($scenario, JSON_THROW_ON_ERROR),
    ], [0 => ['pipe', 'r'], 1 => ['file', $root . '/stdout', 'w'], 2 => ['file', $root . '/stderr', 'w']],
      $pipes, $root, array_replace(getenv(), ['COLUMNS' => '117', 'LINES' => '31', 'TERM' => 'dumb',
        'ICHILOTO_RENDERER' => $scenario['renderer'] ?? 'terminal',
        'ICHILOTO_TEST_TERMINAL_SIZE' => $scenario['terminal'] ?? '31 117']));
    expect(is_resource($process))->toBeTrue();
    fclose($pipes[0]);
    $exitCode = proc_close($process);
    $diagnostics = file_get_contents($root . '/stderr') . (is_file($root . '/logs/error.log') ? file_get_contents($root . '/logs/error.log') : '');
    $this->assertSame(0, $exitCode, $diagnostics);
    expect(json_decode(file_get_contents($root . '/dimensions.json'), true))->toBe($expected)
      ->and(file_get_contents($root . '/stdout'))->toBe('');
    $observations = json_decode(file_get_contents($root . '/observations.json'), true);
    expect($observations['settings'])->toBe($expected)
      ->and($observations['options'])->toBe($expected)
      ->and($observations['cameras'])->toHaveCount(5);
    foreach ($observations['cameras'] as $camera) { expect($camera)->toBe($expected); }
    if ($scenario['start'] ?? false) {
      $hello = json_decode(file($root . '/wire.ndjson')[0], true);
      expect(['width' => $hello['grid']['columns'], 'height' => $hello['grid']['rows']])->toBe($expected);
    }
  } finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
      $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
  }
})->with([
  'ordinary defaults follow terminal' => [[], ['width' => 117, 'height' => 31]],
  'nondefault positional dimensions' => [['width' => 135, 'height' => 40], ['width' => 135, 'height' => 40]],
  'explicit flat options including default height' => [['options' => ['width' => 135, 'height' => 36]], ['width' => 135, 'height' => 36]],
  'explicit nested options survive constructor' => [['options' => ['screen' => ['width' => 170, 'height' => 36]]], ['width' => 170, 'height' => 36]],
  'graphical small terminal' => [[], ['width' => 135, 'height' => 36], ['renderer' => 'gpui', 'terminal' => '24 80', 'start' => true]],
  'graphical large terminal' => [[], ['width' => 135, 'height' => 36], ['renderer' => 'gpui', 'terminal' => '60 220', 'start' => true]],
  'terminal still fills large tty' => [[], ['width' => 220, 'height' => 60], ['terminal' => '60 220']],
  'graphical explicit legacy dimensions' => [['options' => ['width' => 170, 'height' => 36]], ['width' => 170, 'height' => 36], ['renderer' => 'gpui', 'start' => true]],
  'graphical per-axis width override' => [['options' => ['width' => 160]], ['width' => 160, 'height' => 36], ['renderer' => 'gpui', 'start' => true]],
  'graphical per-axis height override' => [['options' => ['screen' => ['height' => 40]]], ['width' => 135, 'height' => 40], ['renderer' => 'gpui', 'start' => true]],
  'graphical positional override' => [['width' => 150, 'height' => 40], ['width' => 150, 'height' => 40], ['renderer' => 'gpui', 'start' => true]],
  'later runtime replaces terminal auto sizes' => [[], ['width' => 135, 'height' => 36], ['terminal' => '60 220', 'attach' => true, 'start' => true]],
  'later runtime preserves positional width' => [['width' => 150], ['width' => 150, 'height' => 36], ['attach' => true, 'start' => true]],
  'later runtime preserves repeated nested configure' => [[], ['width' => 160, 'height' => 40], ['configure' => [['width' => 180], ['screen' => ['width' => 160, 'height' => 40]]], 'attach' => true, 'start' => true]],
  'non-size configure does not freeze terminal auto dimensions' => [[], ['width' => 135, 'height' => 36], ['configure' => [['fps' => 30]], 'attach' => true, 'start' => true]],
  'explicit runtime overrides unknown launch intent' => [[], ['width' => 135, 'height' => 36], ['renderer' => 'unknown', 'attach' => true, 'start' => true]],
]);
