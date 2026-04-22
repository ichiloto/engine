<?php

use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;

function invokeInputManagerMethod(string $methodName, mixed ...$arguments): mixed
{
  $method = new ReflectionMethod(InputManager::class, $methodName);

  return $method->invokeArgs(null, $arguments);
}

it('maps macOS home and end escape sequences', function () {
  expect(invokeInputManagerMethod('getKey', "\033[H"))->toBe(KeyCode::HOME->value)
    ->and(invokeInputManagerMethod('getKey', "\033OH"))->toBe(KeyCode::HOME->value)
    ->and(invokeInputManagerMethod('getKey', "\033[F"))->toBe(KeyCode::END->value)
    ->and(invokeInputManagerMethod('getKey', "\033OF"))->toBe(KeyCode::END->value);
});

it('preserves the existing VT100 key mappings used on Linux terminals', function () {
  expect(invokeInputManagerMethod('getKey', "\033[A"))->toBe(KeyCode::UP->value)
    ->and(invokeInputManagerMethod('getKey', "\033[B"))->toBe(KeyCode::DOWN->value)
    ->and(invokeInputManagerMethod('getKey', "\033[C"))->toBe(KeyCode::RIGHT->value)
    ->and(invokeInputManagerMethod('getKey', "\033[D"))->toBe(KeyCode::LEFT->value)
    ->and(invokeInputManagerMethod('getKey', "\033[7~"))->toBe(KeyCode::HOME->value)
    ->and(invokeInputManagerMethod('getKey', "\033[8~"))->toBe(KeyCode::END->value)
    ->and(invokeInputManagerMethod('getKey', "\033[4~"))->toBe(KeyCode::END->value)
    ->and(invokeInputManagerMethod('getKey', "\n"))->toBe(KeyCode::ENTER->value);
});

it('maps carriage return to enter', function () {
  expect(invokeInputManagerMethod('getKey', "\r"))->toBe(KeyCode::ENTER->value);
});

it('treats an empty non-blocking stream as no input', function () {
  $stream = fopen('php://temp', 'r+');

  expect($stream)->not->toBeFalse();

  stream_set_blocking($stream, false);

  expect(invokeInputManagerMethod('readInputSequence', $stream))->toBe('');

  fclose($stream);
});
