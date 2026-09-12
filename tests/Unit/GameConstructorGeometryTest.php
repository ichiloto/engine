<?php

it('resolves real Game constructor geometry without mistaking injected defaults for caller intent', function (array $arguments, array $expected) {
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
    ], [0 => ['pipe', 'r'], 1 => ['file', $root . '/stdout', 'w'], 2 => ['file', $root . '/stderr', 'w']],
      $pipes, $root, array_replace(getenv(), ['COLUMNS' => '117', 'LINES' => '31', 'TERM' => 'dumb']));
    expect(is_resource($process))->toBeTrue();
    fclose($pipes[0]);
    $exitCode = proc_close($process);
    $diagnostics = file_get_contents($root . '/stderr') . (is_file($root . '/logs/error.log') ? file_get_contents($root . '/logs/error.log') : '');
    $this->assertSame(0, $exitCode, $diagnostics);
    expect(json_decode(file_get_contents($root . '/dimensions.json'), true))->toBe($expected)
      ->and(file_get_contents($root . '/stdout'))->toBe('');
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
]);
