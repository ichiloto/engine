<?php

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Events\KeyboardEvent;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\InputSources\RendererInputSource;
use Ichiloto\Engine\IO\InputSources\TerminalInputSource;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererEventType;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\InputConfig;
use Tests\Support\Input\FakeInputSource;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';
require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

it('carries expanded v2 identities through the client source manager and case-sensitive PHP bindings', function ($identity, $other) {
  $code = KeyCode::from($identity);
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport);
  $client->start(new RendererSessionConfig('Input', sys_get_temp_dir(), protocol: RendererProtocolVersion::V2));
  $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'key', 'key' => $identity]))];
  InputManager::setInputSource(new RendererInputSource($client));
  InputManager::setBindings(['existing-action' => ['keys' => [$code]], 'different-action' => ['keys' => [KeyCode::from($other)]]]);
  InputManager::handleInput();
  expect(InputManager::getPressedKeyCode())->toBe($code)
    ->and(Input::isKeyDown($code))->toBeTrue()
    ->and(Input::isButtonDown('existing-action'))->toBeTrue()
    ->and(Input::isButtonDown('different-action'))->toBeFalse();
})->with([
  ['c', 'C'], ['C', 'c'], ['m', 'M'], ['M', 'm'], ['t', 'T'], ['T', 't'],
  ['tab', 'shift_tab'], ['shift_tab', 'tab'], ['f5', 'f4'],
]);

beforeEach(function () {
  $this->oldSource = InputManager::getInputSource();
  $this->oldBindings = InputManager::getBindings();
  $this->oldDefaults = InputManager::getDefaultBindings();
  $this->oldEventManager = new ReflectionProperty(InputManager::class, 'eventManager')->getValue();
  InputManager::setInputSource(new FakeInputSource());
  new ReflectionProperty(InputManager::class, 'eventManager')->setValue(null, null);
});

afterEach(function () {
  InputManager::setInputSource($this->oldSource);
  new ReflectionProperty(InputManager::class, 'config')->setValue(null, $this->oldBindings);
  new ReflectionProperty(InputManager::class, 'defaultConfig')->setValue(null, $this->oldDefaults);
  new ReflectionProperty(InputManager::class, 'eventManager')->setValue(null, $this->oldEventManager);
});

function inputCompatibilityStream(string $bytes)
{
  $stream = fopen('php://temp', 'r+');
  fwrite($stream, $bytes);
  rewind($stream);
  return $stream;
}

// The original private-parser tests now exercise the extracted public source contract.
it('maps macOS home and end escape sequences', function () {
  $stream = inputCompatibilityStream("\033[H\033OH\033[F\033OF");
  try {
    $source = new TerminalInputSource($stream);
    expect([$source->poll(), $source->poll(), $source->poll(), $source->poll()])
      ->toBe([KeyCode::HOME, KeyCode::HOME, KeyCode::END, KeyCode::END]);
  } finally {
    fclose($stream);
  }
});

it('preserves the existing VT100 key mappings used on Linux terminals', function () {
  $stream = inputCompatibilityStream("\033[A\033[B\033[C\033[D\033[7~\033[8~\033[4~\n");
  try {
    $source = new TerminalInputSource($stream);
    $keys = [];
    for ($i = 0; $i < 8; $i++) { $keys[] = $source->poll(); }
    expect($keys)->toBe([KeyCode::UP, KeyCode::DOWN, KeyCode::RIGHT, KeyCode::LEFT,
      KeyCode::HOME, KeyCode::END, KeyCode::END, KeyCode::ENTER]);
  } finally {
    fclose($stream);
  }
});

it('maps carriage return to enter', function () {
  $stream = inputCompatibilityStream("\r");
  try {
    expect(new TerminalInputSource($stream)->poll())->toBe(KeyCode::ENTER);
  } finally {
    fclose($stream);
  }
});

it('treats an empty non-blocking stream as no input', function () {
  $stream = inputCompatibilityStream('');
  try {
    expect(new TerminalInputSource($stream)->poll())->toBeNull();
  } finally {
    fclose($stream);
  }
});

it('consumes only one key sequence per poll and buffers the remaining bytes', function () {
  $stream = inputCompatibilityStream("\033[A\033[A");
  try {
    $source = new TerminalInputSource($stream);
    expect($source->poll())->toBe(KeyCode::UP)->and($source->poll())->toBe(KeyCode::UP)
      ->and($source->poll())->toBeNull();
  } finally {
    fclose($stream);
  }
});

it('defaults to terminal input and preserves explicit source installation across init', function () {
  new ReflectionProperty(InputManager::class, 'inputSource')->setValue(null, null);
  expect(InputManager::getInputSource())->toBeInstanceOf(TerminalInputSource::class);
  $source = new FakeInputSource(KeyCode::W);
  InputManager::setInputSource($source);
  $oldConfig = ConfigStore::has(InputConfig::class) ? ConfigStore::get(InputConfig::class) : null;
  $oldEventSingleton = new ReflectionProperty(EventManager::class, 'instance')->getValue();
  $config = new class extends InputConfig {
    public int $writes = 0;
    protected function load(): array { return ['up' => ['keys' => [KeyCode::W]]]; }
    public function persist(): void { $this->writes++; }
  };
  try {
    ConfigStore::put(InputConfig::class, $config);
    InputManager::init(new class extends Game {
      public function __construct() {}
      public function __destruct() {}
    });
    $effective = InputManager::getBindings();
    expect(InputManager::getInputSource())->toBe($source)
      ->and($effective['up'])->toBe($config->all()['up'])
      ->and($effective['info']['keys'])->toBe([KeyCode::i, KeyCode::I])
      ->and(InputManager::getDefaultBindings())->toBe($effective)
      ->and($config->all())->not->toHaveKey('info')->and($config->writes)->toBe(0);
    InputManager::setBindings($config->all());
    expect(InputManager::getBindings())->toBe($effective);
    InputManager::setBinding('info', [KeyCode::F2]);
    expect(InputManager::getDefaultBindings())->toBe($effective);
    InputManager::setBindings(InputManager::getDefaultBindings());
    expect(InputManager::getBindings())->toBe($effective)->and($config->writes)->toBe(0);
    InputManager::handleInput();
    expect(InputManager::getPressedKeyCode())->toBe(KeyCode::W);
  } finally {
    $oldConfig === null ? ConfigStore::remove(InputConfig::class) : ConfigStore::put(InputConfig::class, $oldConfig);
    new ReflectionProperty(EventManager::class, 'instance')->setValue(null, $oldEventSingleton);
  }
});

it('adds a missing Info action using only unclaimed default keys', function (array $occupied, array $available) {
  $authored = ['custom' => ['description' => 'Authored action.', 'keys' => $occupied]];
  InputManager::setBindings($authored);
  expect(InputManager::getBindings()['custom'])->toBe($authored['custom'])
    ->and(InputManager::getBindings()['info'])->toBe([
      'description' => 'Read the next Info page; wrap to the first.', 'keys' => $available,
    ])->and($authored)->not->toHaveKey('info');
  foreach ([KeyCode::i, KeyCode::I] as $key) {
    InputManager::setInputSource(new FakeInputSource($key, $key));
    InputManager::handleInput();
    expect(Input::isButtonDown('info'))->toBe(in_array($key, $available, true))
      ->and(Input::isButtonDown('custom'))->toBe(in_array($key, $occupied, true));
    InputManager::handleInput();
    expect(Input::isButtonDown('info'))->toBeFalse();
  }
})->with([
  'unused' => [[], [KeyCode::i, KeyCode::I]],
  'lowercase occupied' => [[KeyCode::i], [KeyCode::I]],
  'uppercase occupied' => [[KeyCode::I], [KeyCode::i]],
  'both occupied' => [[KeyCode::i, KeyCode::I], []],
]);

it('preserves an explicit Info entry including deliberate unbinding', function (array $entry) {
  $authored = ['info' => $entry, 'custom' => ['keys' => [KeyCode::F2]]];
  InputManager::setBindings($authored);
  expect(InputManager::getBindings())->toBe([...$authored,
    'dialogue_auto' => ['description' => 'Toggle automatic dialogue advance.', 'keys' => [KeyCode::F3]],
  ]);
  InputManager::setInputSource(new FakeInputSource(KeyCode::i));
  InputManager::handleInput();
  expect(Input::isButtonDown('info'))->toBeFalse();
})->with([
  'rebound' => [['description' => 'Custom Info.', 'keys' => [KeyCode::F2]]],
  'empty keys' => [['description' => 'Disabled Info.', 'keys' => []]],
  'empty entry' => [[]],
]);

it('does not reapply defaults after an explicit live rebind or unbind', function () {
  InputManager::setBindings([]);
  expect(InputManager::setBinding('info', [KeyCode::F2]))->toBeTrue();
  $rebound = InputManager::getBindings();
  InputManager::setBindings($rebound);
  expect(InputManager::getBindings())->toBe($rebound);
  expect(InputManager::setBinding('info', []))->toBeTrue();
  InputManager::setBindings(InputManager::getBindings());
  expect(InputManager::getBindings()['info']['keys'])->toBeEmpty();
});

it('keeps pressed, down, repeat, release, and any-key semantics source independent', function () {
  InputManager::setInputSource(new FakeInputSource(KeyCode::a, KeyCode::a, KeyCode::b, null));
  InputManager::handleInput();
  expect(Input::isKeyPressed(KeyCode::a))->toBeTrue()->and(Input::isKeyDown(KeyCode::a))->toBeTrue()
    ->and(Input::isAnyKeyPressed([KeyCode::a, KeyCode::b]))->toBeTrue()
    ->and(Input::areAllKeysPressed([KeyCode::a, KeyCode::b]))->toBeFalse();
  InputManager::handleInput();
  expect(Input::isKeyPressed(KeyCode::a))->toBeTrue()->and(Input::isKeyDown(KeyCode::a))->toBeFalse()
    ->and(Input::isAnyKeyPressed([KeyCode::a]))->toBeFalse();
  InputManager::handleInput();
  // Historical release semantics: changing A to B is not an A release; no input is required.
  expect(Input::isKeyUp(KeyCode::a))->toBeFalse()->and(Input::isKeyDown(KeyCode::b))->toBeTrue();
  InputManager::handleInput();
  expect(InputManager::getPressedKeyCode())->toBeNull()->and(Input::isKeyUp(KeyCode::b))->toBeTrue()
    ->and(Input::isAnyKeyReleased([KeyCode::a, KeyCode::b]))->toBeTrue();
});

it('normalizes special-key pressed queries consistently with key-down queries', function () {
  InputManager::setInputSource(new FakeInputSource(KeyCode::UP, null, KeyCode::ENTER));
  InputManager::handleInput();
  expect(Input::isKeyPressed(KeyCode::UP))->toBeTrue()->and(Input::isKeyDown(KeyCode::UP))->toBeTrue();
  InputManager::handleInput();
  InputManager::handleInput();
  expect(Input::isKeyPressed(KeyCode::ENTER))->toBeTrue();
});

it('preserves edge-triggered project bindings and case-sensitive axes', function () {
  InputManager::setBindings([
    'up' => ['keys' => [KeyCode::W]], 'down' => ['keys' => [KeyCode::s]],
    'left' => ['keys' => [KeyCode::a]], 'right' => ['keys' => [KeyCode::d]],
    'confirm' => ['keys' => [KeyCode::ENTER]],
  ]);
  InputManager::setInputSource(new FakeInputSource(KeyCode::w, KeyCode::W, KeyCode::W, KeyCode::s, KeyCode::a, KeyCode::d, KeyCode::ENTER));
  foreach ([0.0, -1.0, 0.0, 1.0] as $vertical) {
    InputManager::handleInput();
    expect(Input::getAxis(AxisName::VERTICAL))->toBe($vertical);
  }
  foreach ([-1.0, 1.0] as $horizontal) {
    InputManager::handleInput();
    expect(Input::getAxis(AxisName::HORIZONTAL))->toBe($horizontal);
  }
  InputManager::handleInput();
  expect(Input::isButtonDown('confirm'))->toBeTrue()->and(Input::isButtonDown('missing'))->toBeFalse();
});

it('dispatches normalized keyboard events including repeated keys', function () {
  $events = new class extends EventManager {
    public array $received = [];
    public function __construct() {}
    public function dispatchEvent(EventInterface $event): bool { $this->received[] = $event; return true; }
  };
  new ReflectionProperty(InputManager::class, 'eventManager')->setValue(null, $events);
  InputManager::setInputSource(new FakeInputSource(KeyCode::UP, KeyCode::UP, KeyCode::W, null));
  for ($i = 0; $i < 4; $i++) { InputManager::handleInput(); }
  expect(array_map(fn(KeyboardEvent $event) => $event->getKey(), $events->received))
    ->toBe([KeyCode::UP, KeyCode::UP, KeyCode::W]);
});

it('clears current and previous state on source switching without draining the new source', function () {
  InputManager::setInputSource(new FakeInputSource(KeyCode::a));
  InputManager::handleInput();
  $source = new FakeInputSource(null, KeyCode::W);
  InputManager::setInputSource($source);
  expect(InputManager::getPressedKeyCode())->toBeNull()->and(Input::isKeyUp(KeyCode::a))->toBeFalse();
  InputManager::handleInput();
  expect(Input::isKeyUp(KeyCode::a))->toBeFalse();
  InputManager::handleInput();
  expect(Input::isKeyDown(KeyCode::W))->toBeTrue();
});

it('delegates reset flags and clears both normalized key states', function ($drain) {
  $source = new FakeInputSource(KeyCode::w, KeyCode::W);
  InputManager::setInputSource($source);
  InputManager::handleInput();
  InputManager::resetState($drain);
  expect($source->resets)->toBe([$drain])->and(InputManager::getPressedKeyCode())->toBeNull()
    ->and(Input::isKeyUp(KeyCode::w))->toBeFalse();
  InputManager::handleInput();
  expect(InputManager::getPressedKeyCode())->toBe($drain ? null : KeyCode::W);
})->with([false, true]);

it('feeds renderer keys into the unchanged Input facade and PHP bindings', function ($identity, $code, $axis, $value) {
  $transport = new FakeRendererTransport();
  $transport->batches[] = [
    RendererEvent::fromJson(json_encode(['protocol' => 1, 'type' => 'key', 'key' => $identity])),
    RendererEvent::fromJson('{"protocol":1,"type":"close_requested"}'),
  ];
  $client = new RendererClient($transport);
  InputManager::setInputSource(new RendererInputSource($client));
  InputManager::setBindings([
    'up' => ['keys' => [KeyCode::UP, KeyCode::w]], 'left' => ['keys' => [KeyCode::a]],
    'confirm' => ['keys' => [KeyCode::ENTER]],
  ]);
  InputManager::handleInput();
  expect(InputManager::getPressedKeyCode())->toBe($code)->and(Input::getAxis($axis))->toBe($value);
  if ($identity === 'enter') { expect(Input::isButtonDown('confirm'))->toBeTrue(); }
  InputManager::resetState(true);
  expect($client->pollEvents()[0]->type)->toBe(RendererEventType::CLOSE_REQUESTED);
})->with([
  ['up', KeyCode::UP, AxisName::VERTICAL, -1.0], ['w', KeyCode::w, AxisName::VERTICAL, -1.0],
  ['a', KeyCode::a, AxisName::HORIZONTAL, -1.0], ['enter', KeyCode::ENTER, AxisName::VERTICAL, 0.0],
]);
