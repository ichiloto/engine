<?php

use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\InputSources\RendererInputSource;
use Ichiloto\Engine\IO\InputSources\TerminalInputSource;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererEventType;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Tests\Support\Input\FakeInputSource;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';
require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

/** A renderer session that subscribed to key transitions, as the Game's GPUI session does. */
function heldInputSession(bool $transitions = true): array
{
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport);
  $required = $transitions ? [RendererSessionConfig::KEY_TRANSITIONS] : [];
  $client->start(new RendererSessionConfig('Held input', sys_get_temp_dir(), protocol: RendererProtocolVersion::V2,
    requiredCapabilities: $required));
  $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready',
    'capabilities' => ['sprite_source_rect', ...$required]]))];
  $client->pump();
  $client->drainEvents();
  $source = new RendererInputSource($client);
  InputManager::setInputSource($source);
  return [$transport, $client, $source];
}

function heldKey(string $key, ?string $control = null, bool $repeat = false): RendererEvent
{
  return RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'key', 'key' => $key,
    'control' => $control ?? strtolower($key), 'repeat' => $repeat]));
}

function heldRelease(string $control): RendererEvent
{
  return RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'key_release', 'control' => $control]));
}

function heldReset(): RendererEvent
{
  return RendererEvent::fromJson('{"protocol":2,"type":"input_reset"}');
}

/**
 * One game-loop iteration carrying this batch of renderer events: the runtime
 * pumps the shared client, then input is handled once.
 */
function heldUpdate(FakeRendererTransport $transport, RendererEvent ...$events): void
{
  $transport->batches[] = $events;
  $source = InputManager::getInputSource();
  new ReflectionProperty(RendererInputSource::class, 'client')->getValue($source)->pump();
  InputManager::handleInput();
}

beforeEach(function () {
  $this->oldSource = InputManager::getInputSource();
  $this->oldBindings = InputManager::getBindings();
  $this->oldEventManager = new ReflectionProperty(InputManager::class, 'eventManager')->getValue();
  new ReflectionProperty(InputManager::class, 'eventManager')->setValue(null, null);
  InputManager::setBindings([
    'up' => ['keys' => [KeyCode::UP, KeyCode::w]], 'down' => ['keys' => [KeyCode::DOWN, KeyCode::s]],
    'left' => ['keys' => [KeyCode::LEFT, KeyCode::a]], 'right' => ['keys' => [KeyCode::RIGHT, KeyCode::d]],
    'confirm' => ['keys' => [KeyCode::ENTER]],
  ]);
});

afterEach(function () {
  InputManager::setInputSource($this->oldSource);
  new ReflectionProperty(InputManager::class, 'config')->setValue(null, $this->oldBindings);
  new ReflectionProperty(InputManager::class, 'eventManager')->setValue(null, $this->oldEventManager);
});

it('parses transition keys, releases and resets only as protocol two subscription events', function () {
  $press = heldKey('S', 's', true);
  expect([$press->type, $press->key, $press->control, $press->repeat])->toBe([RendererEventType::KEY, 'S', 's', true])
    ->and(heldRelease('page_up')->control)->toBe('page_up')
    ->and(heldReset()->type)->toBe(RendererEventType::INPUT_RESET);
  $legacy = RendererEvent::fromJson('{"protocol":2,"type":"key","key":"up"}');
  expect([$legacy->control, $legacy->repeat])->toBe([null, null]);
  foreach ([
    '{"protocol":1,"type":"key","key":"up","control":"up","repeat":false}',
    '{"protocol":1,"type":"key_release","control":"up"}',
    '{"protocol":1,"type":"input_reset"}',
    '{"protocol":2,"type":"key","key":"up","control":"up"}',
    '{"protocol":2,"type":"key","key":"up","repeat":false}',
    '{"protocol":2,"type":"key","key":"up","control":"Up","repeat":false}',
    '{"protocol":2,"type":"key","key":"up","control":"","repeat":false}',
    '{"protocol":2,"type":"key","key":"up","control":"up","repeat":"no"}',
    '{"protocol":2,"type":"key_release"}',
    '{"protocol":2,"type":"key_release","control":"up","repeat":false}',
    '{"protocol":2,"type":"ready","control":"up"}',
  ] as $json) {
    expect(fn() => RendererEvent::fromJson($json))->toThrow(RendererProtocolException::class);
  }
});

it('rejects transitions a session did not subscribe to and unidentified keys in one that did', function () {
  [$transport, $client] = heldInputSession(false);
  expect(InputManager::isHeldInputAvailable())->toBeFalse();
  $transport->batches[] = [heldKey('up')];
  expect(fn() => $client->pump())->toThrow(RendererProtocolException::class, 'negotiated key_transitions');

  [$transport, $client] = heldInputSession();
  expect(InputManager::isHeldInputAvailable())->toBeTrue();
  $transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"key","key":"up"}')];
  expect(fn() => $client->pump())->toThrow(RendererProtocolException::class, 'control identity on every key');
});

it('keeps OS repeats in the event-only key stream while only presses releases and resets change held state', function () {
  [$transport, $client, $source] = heldInputSession();
  $transport->batches[] = [heldKey('down'), heldKey('down', repeat: true), heldKey('down', repeat: true),
    heldRelease('down'), heldReset()];
  $client->pump();
  expect(array_map(static fn($event) => $event->type, $client->drainKeyTransitions()))
    ->toBe([RendererEventType::KEY, RendererEventType::KEY_RELEASE, RendererEventType::INPUT_RESET])
    ->and([$source->poll(), $source->poll(), $source->poll(), $source->poll()])
    ->toBe([KeyCode::DOWN, KeyCode::DOWN, KeyCode::DOWN, null])
    ->and($client->drainEvents())->toBe([]);
  // The event-only stream keeps its bound; a flood of transitions is rejected as a batch.
  $bounded = new RendererClient($small = new FakeRendererTransport(), maxPendingKeys: 2);
  $bounded->start(new RendererSessionConfig('Bound', sys_get_temp_dir(), protocol: RendererProtocolVersion::V2,
    requiredCapabilities: [RendererSessionConfig::KEY_TRANSITIONS]));
  $small->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"ready","capabilities":["key_transitions"]}')];
  $bounded->pump();
  $small->batches[] = [heldRelease('a'), heldRelease('b'), heldReset()];
  expect(fn() => $bounded->pump())->toThrow(RendererTransportException::class, 'capacity');
});

it('lets the most recent held direction win and falls back to the next one still held', function () {
  [$transport] = heldInputSession();
  $latest = static function (): ?string {
    $orders = array_filter(array_map(InputManager::getButtonPressOrder(...), ['up' => 'up', 'down' => 'down',
      'left' => 'left', 'right' => 'right']), static fn(?int $order): bool => $order !== null);
    arsort($orders);
    return array_key_first($orders);
  };
  heldUpdate($transport, heldKey('down'));
  expect(Input::isButtonHeld('down'))->toBeTrue()->and($latest())->toBe('down');
  heldUpdate($transport, heldKey('down', repeat: true), heldKey('right'));
  expect(Input::isButtonHeld('down'))->toBeTrue()->and(Input::isButtonHeld('right'))->toBeTrue()
    ->and($latest())->toBe('right');
  heldUpdate($transport, heldRelease('right'));
  expect(Input::isButtonHeld('right'))->toBeFalse()->and($latest())->toBe('down');
  heldUpdate($transport, heldRelease('down'));
  expect(Input::isButtonHeld('down'))->toBeFalse()->and($latest())->toBeNull();
  // Opposing directions follow the same rule.
  heldUpdate($transport, heldKey('up'));
  heldUpdate($transport, heldKey('down'));
  expect($latest())->toBe('down');
  heldUpdate($transport, heldRelease('down'));
  expect($latest())->toBe('up');
});

it('holds an action through any of its bindings and ignores repeats as new presses', function () {
  [$transport] = heldInputSession();
  heldUpdate($transport, heldKey('down'));
  $order = InputManager::getButtonPressOrder('down');
  expect(InputManager::wasButtonPressed('down'))->toBeTrue();
  heldUpdate($transport, heldKey('down', repeat: true), heldKey('down', repeat: true));
  expect(InputManager::wasButtonPressed('down'))->toBeFalse()
    ->and(InputManager::getButtonPressOrder('down'))->toBe($order);
  heldUpdate($transport, heldKey('s'));
  expect(InputManager::getButtonPressOrder('down'))->toBeGreaterThan($order);
  heldUpdate($transport, heldRelease('down'));
  expect(Input::isButtonHeld('down'))->toBeTrue();
  heldUpdate($transport, heldRelease('s'));
  expect(Input::isButtonHeld('down'))->toBeFalse();
});

it('keeps a quick tap as a press edge for its update and releases by control whatever the modifiers', function () {
  [$transport] = heldInputSession();
  heldUpdate($transport, heldKey('right'), heldRelease('right'));
  expect(Input::isButtonHeld('right'))->toBeFalse()->and(InputManager::wasButtonPressed('right'))->toBeTrue()
    ->and(InputManager::getButtonPressOrder('right'))->not->toBeNull();
  heldUpdate($transport);
  expect(InputManager::wasButtonPressed('right'))->toBeFalse()->and(InputManager::getButtonPressOrder('right'))->toBeNull();
  // Pressed as d, Shift turns its repeats into D; the release still names the key that was pressed.
  heldUpdate($transport, heldKey('d'));
  heldUpdate($transport, heldKey('D', 'd', true));
  expect(Input::isButtonHeld('right'))->toBeTrue();
  heldUpdate($transport, heldRelease('d'));
  expect(Input::isButtonHeld('right'))->toBeFalse();
});

it('forgets held keys on focus loss reset source change restart failure and shutdown', function () {
  [$transport, $client, $source] = heldInputSession();
  heldUpdate($transport, heldKey('down'));
  heldUpdate($transport, heldReset());
  expect(Input::isButtonHeld('down'))->toBeFalse();
  // A release that arrives after the reset is harmless.
  heldUpdate($transport, heldRelease('down'));
  heldUpdate($transport, heldKey('down'));
  expect(Input::isButtonHeld('down'))->toBeTrue();
  InputManager::resetState();
  expect(Input::isButtonHeld('down'))->toBeFalse();
  heldUpdate($transport, heldKey('left'));
  InputManager::setInputSource($source);
  expect(Input::isButtonHeld('left'))->toBeFalse();
  heldUpdate($transport, heldKey('up'));
  $transport->failure = new RendererTransportException('renderer vanished');
  expect(fn() => InputManager::handleInput())->toThrow(RendererTransportException::class)
    ->and(Input::isButtonHeld('up'))->toBeFalse();
});

it('keeps the terminal and other event-only sources event-only', function () {
  InputManager::setInputSource(new TerminalInputSource(fopen('php://memory', 'r')));
  expect(InputManager::isHeldInputAvailable())->toBeFalse()->and(Input::isHeldInputAvailable())->toBeFalse();
  InputManager::setInputSource(new FakeInputSource(KeyCode::DOWN, KeyCode::DOWN));
  InputManager::handleInput();
  expect(Input::isButtonDown('down'))->toBeTrue()->and(Input::isButtonHeld('down'))->toBeFalse()
    ->and(InputManager::wasButtonPressed('down'))->toBeFalse()->and(InputManager::getButtonPressOrder('down'))->toBeNull();
});

it('gives menus the same edges from the same key stream with or without held input', function () {
  $edges = static function (): array {
    $seen = [];
    foreach (range(1, 6) as $_) {
      InputManager::handleInput();
      $seen[] = [Input::isButtonDown('down'), Input::isButtonDown('confirm')];
    }
    return $seen;
  };
  // A held Down autorepeats between frames, then Enter confirms.
  InputManager::setInputSource(new FakeInputSource(KeyCode::DOWN, null, KeyCode::DOWN, KeyCode::DOWN, KeyCode::ENTER, null));
  $legacy = $edges();
  [$transport] = heldInputSession();
  $transport->batches = [[heldKey('down')], [], [heldKey('down', repeat: true)], [heldKey('down', repeat: true)],
    [heldRelease('down'), heldKey('enter')], [heldRelease('enter')]];
  expect($edges())->toBe($legacy);
});
