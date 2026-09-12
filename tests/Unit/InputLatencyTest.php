<?php

use Ichiloto\Engine\Diagnostics\LatencyTrace;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\InputSources\RendererInputSource;
use Ichiloto\Engine\IO\InputSources\TerminalInputSource;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

beforeEach(function () {
  $this->inputState = new ReflectionClass(InputManager::class)->getStaticProperties();
  $this->consoleState = new ReflectionClass(Console::class)->getStaticProperties();
  $this->records = [];
  $this->now = 1_000_000_000;
  LatencyTrace::configure(function (array $record): void { $this->records[] = $record; }, fn() => $this->now);
  $this->events = new class extends EventManager {
    public array $keys = [];
    public function __construct() {}
    public function dispatchEvent(EventInterface $event): bool { $this->keys[] = $event->getKey(); return true; }
  };
  new ReflectionProperty(InputManager::class, 'eventManager')->setValue(null, $this->events);
});

afterEach(function () {
  LatencyTrace::configure();
  foreach ([InputManager::class => $this->inputState, Console::class => $this->consoleState] as $class => $state) {
    foreach ($state as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
});

function latencyKeys(array $keys): array
{
  return array_map(static fn($key) => RendererEvent::fromJson(json_encode([
    'protocol' => 2, 'type' => 'key', 'key' => $key,
  ], JSON_THROW_ON_ERROR)), $keys);
}

it('measures FIFO backlog without discarding repeats or deliberate taps', function (array $keys) {
  $transport = new FakeRendererTransport();
  $transport->batches[] = latencyKeys($keys);
  $client = new RendererClient($transport);
  InputManager::setInputSource(new RendererInputSource($client));
  $client->pump(); // All arrivals occur at t=0; producer then stops.
  foreach ($keys as $index => $key) {
    $this->now = 1_000_000_000 + $index * 40_000_000;
    LatencyTrace::beginIteration();
    InputManager::handleInput();
    expect(InputManager::getPressedKeyCode())->toBe(KeyCode::from($key));
    LatencyTrace::endIteration();
  }
  InputManager::handleInput();
  expect(InputManager::getPressedKeyCode())->toBeNull()
    ->and(array_map(fn($key) => $key->value, $this->events->keys))->toBe($keys);
  $byStage = fn($stage) => array_values(array_filter($this->records, fn($r) => $r['stage'] === $stage));
  expect(array_column($byStage('input.accepted'), 'input_id'))->toBe(range(1, count($keys)))
    ->and(array_column($byStage('game.iteration.end'), 'keys_consumed'))->toBe(array_fill(0, count($keys), 1));
  $dequeued = $byStage('client.key.dequeued');
  expect(end($dequeued)['at_ns'] - $dequeued[0]['at_ns'])->toBe((count($keys) - 1) * 40_000_000);
  $queues = $byStage('input.queue');
  expect($queues[0]['queued_keys'])->toBe(count($keys))
    ->and(max(array_column($queues, 'oldest_age_ns')))->toBe((count($keys) - 2) * 40_000_000);
  // One poll cannot drain an arbitrarily large producer batch or update gameplay repeatedly.
  expect(count($this->events->keys))->toBe(count($keys));
})->with([
  'ten right identities' => [array_fill(0, 10, 'right')],
  'discrete confirm behind directional traffic' => [['right', 'right', 'right', 'enter']],
  'bindings stay ordered' => [['C', 'M', 'T', 'tab', 'shift_tab', 'f5']],
]);

it('retains terminal FIFO and current previous semantics under the same trace', function () {
  $stream = fopen('php://temp', 'r+');
  fwrite($stream, "\033[C\033[C\r");
  rewind($stream);
  try {
    InputManager::setInputSource(new TerminalInputSource($stream));
    foreach ([KeyCode::RIGHT, KeyCode::RIGHT, KeyCode::ENTER] as $i => $key) {
      LatencyTrace::beginIteration();
      InputManager::handleInput();
      expect(InputManager::getPressedKeyCode())->toBe($key)
        ->and(InputManager::isKeyDown($key))->toBe($i !== 1);
      LatencyTrace::endIteration();
    }
    expect($this->events->keys)->toBe([KeyCode::RIGHT, KeyCode::RIGHT, KeyCode::ENTER]);
  } finally { fclose($stream); }
});

it('correlates a real process event from parsing through keyboard dispatch without wire changes', function () {
  $process = new RendererProcessConfig([PHP_BINARY, __DIR__ . '/../Fixtures/Renderer/renderer-stub.php', 'ready_key']);
  $runtime = new RendererRuntime(new RendererRuntimeConfig($process, __DIR__));
  try {
    LatencyTrace::beginIteration();
    $runtime->start('Trace', 135, 36);
    InputManager::handleInput();
    $stages = ['transport.key.parsed', 'client.key.queued', 'client.key.dequeued',
      'input.source.returned', 'input.accepted', 'input.keyboard.dispatch', 'input.keyboard.dispatched'];
    $records = array_values(array_filter($this->records, fn($r) => in_array($r['stage'], $stages, true)));
    expect(array_column($records, 'stage'))->toBe($stages)
      ->and(array_column($records, 'input_id'))->toBe(array_fill(0, count($stages), 1));
  } finally { $runtime->shutdown(); }
});

it('queues a changed styled frame in the input iteration and suppresses unchanged output', function () {
  foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false,
    'output' => null, 'terminalOutputStream' => null] as $name => $value) {
    new ReflectionProperty(Console::class, $name)->setValue(null, $value);
  }
  Console::syncDimensions(135, 36);
  $transport = new FakeRendererTransport();
  $runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), __DIR__), $transport);
  ob_start();
  try {
    $runtime->start('Turnaround', 135, 36);
    foreach (["\e[31mR\e[0m", "\e[32mR\e[0m", "\e[32mR\e[0m"] as $i => $text) {
      $transport->batches[] = latencyKeys(['right']);
      LatencyTrace::beginIteration();
      InputManager::handleInput();
      Console::withLayer('ui', fn() => Console::write($text . '   ', 1, 2));
      expect($runtime->present(null))->toBe($i < 2);
      LatencyTrace::endIteration();
    }
    $stages = array_column($this->records, 'stage');
    expect(array_count_values($stages)['presentation.snapshot'])->toBe(3)
      ->and(array_count_values($stages)['presentation.frame.queued'])->toBe(2)
      ->and($transport->sent)->toHaveCount(2);
    $sent = array_values(array_filter($this->records, fn($r) => $r['stage'] === 'presentation.frame.queued'));
    expect(array_column($sent, 'iteration'))->toBe([1, 2])->and(array_column($sent, 'input_id'))->toBe([1, 2]);
  } finally { $runtime->shutdown(); ob_end_clean(); }
});

it('does not read a diagnostic clock when disabled', function () {
  LatencyTrace::configure(null, fn() => throw new LogicException('disabled clock'));
  LatencyTrace::beginIteration();
  LatencyTrace::record('ignored');
  expect(LatencyTrace::now())->toBeNull();
  LatencyTrace::endIteration();
});
