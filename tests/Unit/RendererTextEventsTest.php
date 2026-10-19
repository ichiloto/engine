<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\Text\TextMeasurement;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererEventType;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererMessageType;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use PHPUnit\Framework\Assert;

function makeTextEventMetrics(): array
{
  return ['advance' => 24.5, 'baseline' => 10,
    'lineBounds' => ['x' => 0, 'y' => 0, 'width' => 24.5, 'height' => 15],
    'inkBounds' => ['x' => -1.25, 'y' => -0.5, 'width' => 25.5, 'height' => 12],
    'paintBounds' => ['x' => -3, 'y' => -2, 'width' => 29, 'height' => 17]];
}

function makeTextEventWire(string $type = 'text_prepared'): array
{
  return ['protocol' => 2, 'type' => $type, ...match ($type) {
    'text_prepared' => ['requestId' => 'synthetic:query:1', 'layoutId' => 'text-1-1', 'metrics' => makeTextEventMetrics()],
    'text_prepare_failed' => ['requestId' => 'synthetic:query:1', 'message' => 'Synthetic font cannot decode'],
    'text_released' => ['layoutIds' => ['text-1-1']],
    default => throw new InvalidArgumentException('Unknown synthetic text event type.'),
  }];
}

function decodeTextEventWire(array $wire): RendererEvent
{
  return RendererEvent::fromJson(json_encode($wire, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
}

function nameTextEventCases(array $cases): array
{
  $named = [];
  foreach ($cases as $index => $case) { $named['case-' . $index] = $case; }
  return $named;
}

it('exposes the exact implemented text event and message vocabulary', function () {
  expect(RendererEventType::TEXT_PREPARED->value)->toBe('text_prepared')
    ->and(RendererEventType::TEXT_PREPARE_FAILED->value)->toBe('text_prepare_failed')
    ->and(RendererEventType::TEXT_RELEASED->value)->toBe('text_released')
    ->and(RendererMessageType::PREPARE_TEXT->value)->toBe('prepare_text')
    ->and(RendererMessageType::RELEASE_TEXT->value)->toBe('release_text');
});

it('decodes the exact Rust reply into parent-owned query result fields with signed bearings', function () {
  $event = decodeTextEventWire(makeTextEventWire());
  $measurement = new TextMeasurement($event->layoutId, $event->textMetrics);
  expect($event->type)->toBe(RendererEventType::TEXT_PREPARED)->and($event->protocol)->toBe(RendererProtocolVersion::V2)
    ->and($event->requestId)->toBe('synthetic:query:1')->and($measurement->layoutId)->toBe('text-1-1')
    ->and($measurement->metrics)->toBe($event->textMetrics)
    ->and($event->textMetrics->inkBounds->x)->toBe(-1.25)->and($event->textMetrics->paintBounds->y)->toBe(-2.0)
    ->and($event->textMetrics->baseline)->toBe(10.0)->and($event->layoutIds)->toBe([])
    ->and($event->message)->toBeNull()->and($event->key)->toBeNull()->and($event->generation)->toBeNull();
});

it('allows explicit null ink and legitimate empty and boundary metric rectangles', function () {
  $metrics = ['advance' => 0, 'baseline' => 16384,
    'lineBounds' => ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 0], 'inkBounds' => null,
    'paintBounds' => ['x' => -16384, 'y' => 16384, 'width' => 16384, 'height' => 0]];
  $event = decodeTextEventWire([...makeTextEventWire(), 'metrics' => $metrics]);
  expect($event->textMetrics->inkBounds)->toBeNull()->and($event->textMetrics->advance)->toBe(0.0)
    ->and($event->textMetrics->paintBounds->x)->toBe(-16384.0)
    ->and($event->textMetrics->lineBounds->width)->toBe(0.0);
});

it('preserves correlated failure diagnostics as messages without converting them to global renderer errors', function (string $message) {
  $event = decodeTextEventWire([...makeTextEventWire('text_prepare_failed'), 'message' => $message]);
  expect($event->type)->toBe(RendererEventType::TEXT_PREPARE_FAILED)->and($event->requestId)->toBe('synthetic:query:1')
    ->and($event->message)->toBe($message)->and($event->layoutId)->toBeNull()
    ->and($event->textMetrics)->toBeNull()->and($event->layoutIds)->toBe([]);
})->with(['empty' => '', 'control and Unicode diagnostic' => "Details\nwith\tcontrols and \u{00E9}",
  'large diagnostic' => str_repeat('diagnostic ', 8192)]);

it('bounds text diagnostics only by the actual whole-wire ceiling without narrowing existing errors', function () {
  $wire = makeTextEventWire('text_prepare_failed');
  $overhead = strlen(json_encode([...$wire, 'message' => ''], JSON_THROW_ON_ERROR));
  $wire['message'] = str_repeat('a', RendererEvent::MAX_TEXT_REPLY_BYTES - $overhead);
  $line = json_encode($wire, JSON_THROW_ON_ERROR);
  expect(strlen($line))->toBe(RendererEvent::MAX_TEXT_REPLY_BYTES)
    ->and(RendererEvent::fromJson($line)->message)->toBe($wire['message']);
  expect(fn() => RendererEvent::fromJson($line . ' '))->toThrow(RendererProtocolException::class, '4 MiB');
  $legacy = decodeTextEventWire(['protocol' => 1, 'type' => 'error', 'message' => $wire['message'] . str_repeat('b', 256)]);
  expect(strlen($legacy->message))->toBe(strlen($wire['message']) + 256);
});

it('preserves release order and duplicates and accepts empty and maximum lists as Rust does', function () {
  foreach ([[], ['text-2', 'text-1', 'text-2'], array_fill(0, 64, 'text-1')] as $ids) {
    $event = decodeTextEventWire([...makeTextEventWire('text_released'), 'layoutIds' => $ids]);
    expect($event->type)->toBe(RendererEventType::TEXT_RELEASED)->and($event->layoutIds)->toBe($ids)
      ->and($event->requestId)->toBeNull()->and($event->layoutId)->toBeNull()->and($event->textMetrics)->toBeNull();
  }
});

it('requires protocol two for every text reply', function (string $type) {
  expect(fn() => decodeTextEventWire([...makeTextEventWire($type), 'protocol' => 1]))->toThrow(RendererProtocolException::class);
})->with(['text_prepared', 'text_prepare_failed', 'text_released']);

it('accepts UTF-8 and format characters in IDs up to the existing byte boundary', function () {
  $id = str_repeat("\u{00E9}", 128);
  $event = decodeTextEventWire([...makeTextEventWire(), 'requestId' => $id, 'layoutId' => "opaque:\u{200D}"]);
  expect($event->requestId)->toBe($id)->and($event->layoutId)->toBe("opaque:\u{200D}");
});

it('rejects malformed text reply IDs without coercion', function (array $wire) {
  expect(fn() => decodeTextEventWire($wire))->toThrow(RendererProtocolException::class);
})->with(function () {
  $cases = [];
  foreach (['', str_repeat('a', 257), str_repeat("\u{00E9}", 129), "text\n", "text\0", "text\u{0085}",
    null, 1, 1.5, false, [], new stdClass()] as $id) {
    foreach (['text_prepared', 'text_prepare_failed'] as $type) {
      $cases[] = [[...makeTextEventWire($type), 'requestId' => $id]];
    }
    $cases[] = [[...makeTextEventWire(), 'layoutId' => $id]];
    $cases[] = [[...makeTextEventWire('text_released'), 'layoutIds' => [$id]]];
  }
  return nameTextEventCases($cases);
});

it('rejects malformed required fields and undefined combinations on text replies', function (array $wire) {
  expect(fn() => decodeTextEventWire($wire))->toThrow(RendererProtocolException::class);
})->with(function () {
  $cases = [];
  foreach (['text_prepared', 'text_prepare_failed', 'text_released'] as $type) {
    $wire = makeTextEventWire($type);
    foreach (array_diff(array_keys($wire), ['protocol', 'type']) as $field) {
      $missing = $wire; unset($missing[$field]); $cases[] = [$missing];
    }
    foreach (['key' => 'up', 'message' => 'extra', 'active' => true, 'generation' => 1, 'frame' => 1,
      'control' => 'key_up', 'repeat' => false, 'capabilities' => [], 'extra' => 1,
      'requestId' => 'query', 'layoutId' => 'text-1', 'metrics' => makeTextEventMetrics(),
      'layoutIds' => [], 'textMetrics' => makeTextEventMetrics()] as $field => $value) {
      if (!array_key_exists($field, $wire)) { $cases[] = [[...$wire, $field => $value]]; }
    }
  }
  foreach ([null, 1, false, [], new stdClass()] as $message) {
    $cases[] = [[...makeTextEventWire('text_prepare_failed'), 'message' => $message]];
  }
  foreach ([null, false, 'text-1', ['id' => 'text-1'], [1 => 'text-1'], new stdClass(), array_fill(0, 65, 'text-1')] as $ids) {
    $cases[] = [[...makeTextEventWire('text_released'), 'layoutIds' => $ids]];
  }
  foreach ([null, [], 1, false, 'metrics'] as $metrics) { $cases[] = [[...makeTextEventWire(), 'metrics' => $metrics]]; }
  return nameTextEventCases($cases);
});

it('rejects malformed metric objects and fields without numeric or rectangle coercion', function (array $metrics) {
  expect(fn() => decodeTextEventWire([...makeTextEventWire(), 'metrics' => $metrics]))->toThrow(RendererProtocolException::class);
})->with(function () {
  $base = makeTextEventMetrics();
  $cases = [[[]], [[...$base, 'extra' => 1]]];
  foreach (array_keys($base) as $field) {
    $missing = $base; unset($missing[$field]); $cases[] = [$missing];
  }
  foreach (['advance', 'baseline'] as $field) {
    foreach (['12', true, null, [], -1, 16384.01, 1e30] as $value) { $cases[] = [[...$base, $field => $value]]; }
  }
  foreach (['lineBounds', 'inkBounds', 'paintBounds'] as $field) {
    foreach ([[], [0, 0, 1, 1], false, 1, 'bounds'] as $value) { $cases[] = [[...$base, $field => $value]]; }
    foreach (['x', 'y', 'width', 'height'] as $number) {
      foreach (['1', true, null] as $value) {
        $cases[] = [[...$base, $field => [...$base[$field], $number => $value]]];
      }
      $missing = $base[$field]; unset($missing[$number]); $cases[] = [[...$base, $field => $missing]];
    }
    foreach (['width', 'height'] as $number) {
      foreach ([-1, 16384.01, 1e30] as $value) {
        $cases[] = [[...$base, $field => [...$base[$field], $number => $value]]];
      }
    }
    $cases[] = [[...$base, $field => [...$base[$field], 'extra' => 1]]];
  }
  foreach (['lineBounds', 'paintBounds'] as $field) { $cases[] = [[...$base, $field => null]]; }
  return nameTextEventCases($cases);
});

it('preserves finite signed metric origins separately from bounded extents', function () {
  foreach (['lineBounds', 'inkBounds', 'paintBounds'] as $field) {
    foreach (['x', 'y'] as $number) {
      foreach ([-32768, 1e30] as $value) {
        $wire = makeTextEventWire();
        $wire['metrics'][$field][$number] = $value;
        $event = decodeTextEventWire($wire);
        expect($event->textMetrics->toArray()[$field][$number])->toBe((float)$value);
      }
    }
  }
});

it('rejects JSON numeric overflow as a protocol error and retains the model validation cause', function () {
  $line = json_encode(makeTextEventWire(), JSON_THROW_ON_ERROR);
  $line = str_replace('"advance":24.5', '"advance":1e400', $line);
  try {
    RendererEvent::fromJson($line);
    Assert::fail('Numeric overflow must be rejected.');
  } catch (RendererProtocolException $error) {
    expect($error->getPrevious())->toBeInstanceOf(InvalidArgumentException::class);
  }
});

it('rejects invalid UTF-8 JSON instead of creating replacement text identities', function () {
  expect(fn() => RendererEvent::fromJson('{"protocol":2,"type":"text_released","layoutIds":["' . "\xFF" . '"]}'))
    ->toThrow(RendererProtocolException::class);
});

it('rejects reserved text fields on all unrelated existing events while preserving their original fields', function () {
  $wires = [
    ['protocol' => 1, 'type' => 'ready', 'capabilities' => ['styled_text']],
    ['protocol' => 1, 'type' => 'key', 'key' => 'up'],
    ['protocol' => 1, 'type' => 'error', 'message' => 'Original diagnostic'],
    ['protocol' => 2, 'type' => 'key', 'key' => 'up', 'control' => 'key_up', 'repeat' => false],
    ['protocol' => 2, 'type' => 'key_release', 'control' => 'key_up'],
    ['protocol' => 2, 'type' => 'input_reset'],
    ['protocol' => 2, 'type' => 'close_requested'],
    ['protocol' => 2, 'type' => 'window_activation', 'active' => false],
    ['protocol' => 2, 'type' => 'resized'],
    ['protocol' => 2, 'type' => 'frame_ack', 'generation' => 3, 'frame' => 4, 'presented' => true],
    ['protocol' => 2, 'type' => 'frame_rejected', 'generation' => 0, 'expectedGeneration' => 3,
      'resyncRequired' => true, 'message' => 'Retained diagnostic'],
  ];
  foreach ($wires as $wire) {
    $event = decodeTextEventWire($wire);
    foreach (array_diff(array_keys($wire), ['protocol', 'type']) as $field) { expect($event->$field)->toBe($wire[$field]); }
    expect($event->requestId)->toBeNull()->and($event->layoutId)->toBeNull()
      ->and($event->textMetrics)->toBeNull()->and($event->layoutIds)->toBe([]);
    foreach (['requestId', 'layoutId', 'metrics', 'layoutIds', 'textMetrics'] as $field) {
      expect(fn() => decodeTextEventWire([...$wire, $field => null]))->toThrow(RendererProtocolException::class);
    }
  }
});
