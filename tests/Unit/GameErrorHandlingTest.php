<?php

it('respects PHP warning suppression without hiding reportable game errors', function (string $mode, int $expectedExit) {
  $root = sys_get_temp_dir() . '/ichiloto-error-handler-' . bin2hex(random_bytes(8));
  mkdir($root);
  $script = <<<'PHP'
    require $argv[1];
    $game = new class extends Ichiloto\Engine\Core\Game {
      public function __construct() {}
      public function __destruct() {}
      protected function stop(): void {}
    };
    $game->configureErrorAndExceptionHandlers();
    if ($argv[2] === 'operator') {
      @trigger_error('optional probe failed', E_USER_WARNING);
    } elseif ($argv[2] === 'mask') {
      error_reporting(E_ALL & ~E_USER_WARNING);
      trigger_error('optional probe failed', E_USER_WARNING);
    } else {
      trigger_error('reportable game failure', E_USER_WARNING);
    }
    echo 'continued';
    PHP;
  try {
    $process = proc_open([PHP_BINARY, '-r', $script, dirname(__DIR__, 2) . '/vendor/autoload.php', $mode],
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    expect(is_resource($process))->toBeTrue();
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe($expectedExit);
    if ($expectedExit === 0) {
      expect($stdout)->toBe('continued')->and($stderr)->toBe('')
        ->and(file_exists($root . '/logs/error.log'))->toBeFalse();
    } else {
      expect($stdout)->not->toContain('continued')
        ->and($stderr)->toContain('The game crashed. Details were written to')
        ->and(file_get_contents($root . '/logs/error.log'))->toContain('reportable game failure');
    }
  } finally {
    foreach (glob($root . '/logs/*') ?: [] as $file) { unlink($file); }
    if (is_dir($root . '/logs')) { rmdir($root . '/logs'); }
    rmdir($root);
  }
})->with([['operator', 0], ['mask', 0], ['reported', 1]]);
