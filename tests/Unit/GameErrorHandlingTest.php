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

it('tears down scenes before platform resources on caught startup and loop errors without masking the crash', function (string $phase) {
  $root = sys_get_temp_dir() . '/ichiloto-loop-teardown-' . bin2hex(random_bytes(8));
  mkdir($root);
  $script = <<<'PHP'
    require $argv[1];
    use Assegai\Collections\ItemList;
    use Ichiloto\Engine\Audio\AudioManager;
    use Ichiloto\Engine\Core\Game;
    use Ichiloto\Engine\Events\Interfaces\ObserverInterface;
    use Ichiloto\Engine\Events\Interfaces\StaticObserverInterface;
    use Ichiloto\Engine\IO\Console\Console;
    use Ichiloto\Engine\Scenes\AbstractScene;
    use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
    use Ichiloto\Engine\Scenes\SceneManager;

    Console::setTerminalOutputEnabled(false);
    $game = new class($argv[2]) extends Game {
      public function __construct(private string $phase) {
        $this->options = ['fps' => 1000];
        $this->observers = new ItemList(ObserverInterface::class);
        $this->staticObservers = new ItemList(StaticObserverInterface::class);
        $this->audioManager = new class extends AudioManager {
          public function __construct() {}
          public function shutdown(): void { echo "audio\n"; }
        };
        $this->sceneManager = new ReflectionClass(SceneManager::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(SceneManager::class, 'scenes')->setValue($this->sceneManager, new ItemList(SceneInterface::class));
        foreach (['broken', 'current'] as $name) {
          $scene = new class($name) extends AbstractScene {
            public function __construct(private string $label) { $this->started = $label === 'broken'; }
            public function stop(): void {
              echo $this->label . "\n";
              if ($this->label === 'broken') { throw new RuntimeException('secondary teardown failure'); }
            }
          };
          $this->sceneManager->addScenes($scene);
          $this->sceneManager->currentScene = $scene;
        }
      }
      public function __destruct() {}
      protected function start(): void {
        $this->isRunning = true;
        if ($this->phase === 'startup') { throw new RuntimeException('primary startup failure'); }
      }
      protected function handleInput(): void {}
      protected function update(): void { throw new RuntimeException('primary loop failure'); }
    };
    $game->run();
    echo 'unreachable';
    PHP;
  try {
    $process = proc_open([PHP_BINARY, '-r', $script, dirname(__DIR__, 2) . '/vendor/autoload.php', $phase],
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    expect(is_resource($process))->toBeTrue();
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(1)
      ->and($stdout)->toBe("broken\ncurrent\naudio\n")
      ->and($stderr)->toContain('The game crashed. Details were written to')
      ->and(file_get_contents($root . '/logs/error.log'))
      ->toContain('primary ' . ($phase === 'startup' ? 'startup' : 'loop') . ' failure', 'secondary teardown failure');
  } finally {
    foreach (glob($root . '/logs/*') ?: [] as $file) { unlink($file); }
    if (is_dir($root . '/logs')) { rmdir($root . '/logs'); }
    rmdir($root);
  }
})->with(['startup', 'loop']);
