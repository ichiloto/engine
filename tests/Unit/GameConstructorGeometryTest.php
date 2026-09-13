<?php

it('resolves real Game constructor geometry without mistaking injected defaults for caller intent', function (array $arguments, array $expected, array $scenario = []) {
  $root = sys_get_temp_dir() . '/ichiloto-game-constructor-' . uniqid();
  mkdir($root . '/assets/Data', 0777, true);
  file_put_contents($root . '/ichiloto.json', '{"id":"constructor-geometry-fixture","debug":{"skip_splash":true},"splash_screen":{"enabled":false}}');
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
    expect(json_decode(file_get_contents($root . '/dimensions.json'), true))->toBe($expected);
    $output = file_get_contents($root . '/stdout');
    if ($scenario['boot'] ?? false) {
      expect($output)->toContain("\e[?1049h")->toContain("\e[?1049l")
        ->and(preg_match('/\x1b\[8;[0-9]+;[0-9]+t/', $output))->toBe(0);
    } else {
      expect($output)->toBe('');
    }
    $observations = json_decode(file_get_contents($root . '/observations.json'), true);
    $assertGeometry = function (array $observation, array $size): void {
      expect($observation['grid'])->toBe($size)
        ->and($observation['settings'])->toBe($size)
        ->and($observation['options'])->toBe($size)
        ->and($observation['cameras'])->toHaveCount(5);
      foreach ($observation['cameras'] as $camera) { expect($camera)->toBe($size); }
    };
    $assertGeometry($observations, $expected);
    if (isset($scenario['origin'])) {
      expect($observations['origin'])->toBe($scenario['origin']);
      $row = $scenario['origin']['y'] + 1;
      $column = $scenario['origin']['x'] + 1;
      expect($output)->toContain("\e[?1049h\e[?7l\e[0m\e[2J\e[{$row};{$column}H");
    }
    foreach ($scenario['resizes'] ?? [] as $index => $resize) {
      $assertGeometry($observations['resizes'][$index], $resize['expected']);
      expect($observations['resizes'][$index]['preserved'])->toBe($resize['preserved']);
      if (isset($resize['origin'])) {
        expect($observations['resizes'][$index]['origin'])->toBe($resize['origin'])
          ->and($observations['resizes'][$index]['probes'])->toBe(($resize['composing'] ?? false) ? 0 : 1);
      }
    }
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
  'terminal positional dimensions fit physical size' => [['width' => 135, 'height' => 40], ['width' => 117, 'height' => 31]],
  'terminal flat options fit physical size' => [['options' => ['width' => 135, 'height' => 36]], ['width' => 117, 'height' => 31]],
  'terminal nested options fit physical size' => [['options' => ['screen' => ['width' => 170, 'height' => 36]]], ['width' => 117, 'height' => 31]],
  'graphical small terminal' => [[], ['width' => 135, 'height' => 36], ['renderer' => 'gpui', 'terminal' => '24 80', 'start' => true]],
  'graphical large terminal' => [[], ['width' => 135, 'height' => 36], ['renderer' => 'gpui', 'terminal' => '60 220', 'start' => true]],
  'terminal large tty is capped' => [[], ['width' => 135, 'height' => 36], ['terminal' => '60 220']],
  'terminal startup does not resize the physical window' => [[], ['width' => 135, 'height' => 36], ['terminal' => '60 220', 'boot' => true]],
  'terminal centered viewport follows margin-only resizes on both axes' => [[], ['width'=>135,'height'=>36], [
    'terminal'=>'38 186','boot'=>true,'origin'=>['x'=>25,'y'=>1], 'resizes'=>[
      ['terminal'=>'60 220','expected'=>['width'=>135,'height'=>36],'preserved'=>true,'origin'=>['x'=>42,'y'=>12]],
      ['terminal'=>'39 187','expected'=>['width'=>135,'height'=>36],'preserved'=>true,'origin'=>['x'=>26,'y'=>1]],
      ['terminal'=>'24 80','expected'=>['width'=>80,'height'=>24],'preserved'=>false,'origin'=>['x'=>0,'y'=>0]],
      ['terminal'=>'38 186','expected'=>['width'=>135,'height'=>36],'preserved'=>false,'origin'=>['x'=>25,'y'=>1]],
  ]]],
  'terminal smaller explicit viewport centers without changing requests' => [['options'=>['width'=>100,'height'=>20]],
    ['width'=>100,'height'=>20], ['terminal'=>'38 186','boot'=>true,'origin'=>['x'=>43,'y'=>9]]],
  'blocked frames center without resetting modal layout or interrupting composition' => [[], ['width'=>135,'height'=>36], [
    'terminal'=>'38 186','boot'=>true,'origin'=>['x'=>25,'y'=>1], 'resizes'=>[
      ['terminal'=>'60 220','blocked'=>true,'composing'=>true,'expected'=>['width'=>135,'height'=>36],'preserved'=>true,'origin'=>['x'=>25,'y'=>1]],
      ['terminal'=>'60 220','blocked'=>true,'expected'=>['width'=>135,'height'=>36],'preserved'=>true,'origin'=>['x'=>42,'y'=>12]],
      ['terminal'=>'24 80','blocked'=>true,'expected'=>['width'=>135,'height'=>36],'preserved'=>true,'origin'=>['x'=>0,'y'=>0]],
      ['terminal'=>'24 80','expected'=>['width'=>80,'height'=>24],'preserved'=>false,'origin'=>['x'=>0,'y'=>0]],
      ['terminal'=>'38 186','expected'=>['width'=>135,'height'=>36],'preserved'=>false,'origin'=>['x'=>25,'y'=>1]],
  ]]],
  'terminal small tty stays usable' => [[], ['width' => 80, 'height' => 24], ['terminal' => '24 80']],
  'terminal wide tty caps width only' => [[], ['width' => 135, 'height' => 24], ['terminal' => '24 220']],
  'terminal tall tty caps height only' => [[], ['width' => 80, 'height' => 36], ['terminal' => '60 80']],
  'terminal explicit sizes cannot exceed cap' => [['options' => ['width' => 220, 'height' => 60]], ['width' => 135, 'height' => 36], ['terminal' => '60 220']],
  'terminal smaller explicit sizes are preserved' => [['options' => ['screen' => ['width' => 100, 'height' => 30]]], ['width' => 100, 'height' => 30], ['terminal' => '60 220']],
  'terminal resize follows effective dimensions without redundant buffer resets' => [[], ['width' => 135, 'height' => 36], ['terminal' => '60 220', 'resizes' => [
    ['terminal' => '40 160', 'expected' => ['width' => 135, 'height' => 36], 'preserved' => true],
    ['terminal' => '24 80', 'expected' => ['width' => 80, 'height' => 24], 'preserved' => false],
    ['terminal' => '60 220', 'expected' => ['width' => 135, 'height' => 36], 'preserved' => false],
    ['terminal' => '70 240', 'expected' => ['width' => 135, 'height' => 36], 'preserved' => true],
  ]]],
  'terminal smaller requests survive shrinking and regrowing tty' => [['options' => ['width' => 100, 'height' => 30]], ['width' => 100, 'height' => 30], ['terminal' => '60 220', 'resizes' => [
    ['terminal' => '24 80', 'expected' => ['width' => 80, 'height' => 24], 'preserved' => false],
    ['terminal' => '60 220', 'expected' => ['width' => 100, 'height' => 30], 'preserved' => false],
  ]]],
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
