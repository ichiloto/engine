<?php

use Ichiloto\Engine\IO\Console\ConsoleFrameSnapshot;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputSources\RendererInputSource;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

beforeEach(function () {
  $this->transport = new FakeRendererTransport();
  $this->client = new RendererClient($this->transport);
  $this->presentation = new RendererPresentation($this->client, new RendererGridConfig(4, 2));
  $this->snapshot = new ConsoleFrameSnapshot(4, 2, ['map ', ' @  ']);
});

it('sequences only changed successfully queued frames and suppresses identical text', function () {
  expect($this->presentation->present($this->snapshot))->toBeTrue()
    ->and($this->presentation->present(clone $this->snapshot))->toBeFalse()
    ->and($this->presentation->present(new ConsoleFrameSnapshot(4, 2, ['map ', '  @ '])))->toBeTrue()
    ->and(array_column(array_map(fn($m) => $m->payload, $this->transport->sent), 'frame'))->toBe([1, 2]);
});

it('compares immutable sprite values strictly and includes their removal in frame state', function () {
  $one = new PresentationSprite('01', '01.png', 0, 0, 32, 48);
  $two = new PresentationSprite('1', '1.png', 0, 0, 32, 48);
  expect($this->presentation->present($this->snapshot, [$one]))->toBeTrue()
    ->and($this->presentation->present($this->snapshot, [clone $one]))->toBeFalse()
    ->and($this->presentation->present($this->snapshot, [$two]))->toBeTrue()
    ->and($this->presentation->present($this->snapshot))->toBeTrue()
    ->and($this->transport->sent[2]->payload['sprites'])->toBe([]);
});

it('normalizes layers for comparison but treats changed equal-layer order as visible state', function () {
  $back = new PresentationSprite('back', 'back.png', 0, 0, 1, 1, layer: -1);
  $front = new PresentationSprite('front', 'front.png', 0, 0, 1, 1, layer: 1);
  $other = new PresentationSprite('other', 'other.png', 0, 0, 1, 1, layer: 1);
  expect($this->presentation->present($this->snapshot, [$front, $back, $other]))->toBeTrue()
    ->and($this->presentation->present($this->snapshot, [$back, $front, $other]))->toBeFalse()
    ->and($this->presentation->present($this->snapshot, [$back, $other, $front]))->toBeTrue();
});

it('rejects mismatched session geometry without sending or consuming a sequence', function ($snapshot) {
  expect(fn() => $this->presentation->present($snapshot))->toThrow(InvalidArgumentException::class, 'fixed renderer session grid');
  expect($this->transport->sent)->toBe([]);
  $this->presentation->present($this->snapshot);
  expect($this->transport->sent[0]->payload['frame'])->toBe(1);
})->with([new ConsoleFrameSnapshot(3, 2, ['   ', '   ']), new ConsoleFrameSnapshot(4, 1, ['    '])]);

it('propagates send pressure without suppressing retries or advancing sequence state', function () {
  $failure = new RendererTransportException('outbound queue full');
  $this->transport->sendFailure = $failure;
  expect(fn() => $this->presentation->present($this->snapshot))->toThrow($failure);
  $this->transport->sendFailure = null;
  expect($this->presentation->present($this->snapshot))->toBeTrue();
  $changed = new ConsoleFrameSnapshot(4, 2, ['new ', ' @  ']);
  $this->transport->sendFailure = $failure;
  expect(fn() => $this->presentation->present($changed))->toThrow($failure);
  expect($this->presentation->present($this->snapshot))->toBeFalse();
  $this->transport->sendFailure = null;
  expect($this->presentation->present($changed))->toBeTrue()
    ->and(array_map(fn($m) => $m->payload['frame'], $this->transport->sent))->toBe([1, 2]);
});

it('shares input and lifecycle state without polling consuming or shutting down the client', function ($pumpFirst) {
  $events = array_map(RendererEvent::fromJson(...), [
    '{"protocol":1,"type":"ready"}', '{"protocol":1,"type":"key","key":"up"}',
    '{"protocol":1,"type":"close_requested"}', '{"protocol":1,"type":"error","message":"fixture"}',
  ]);
  $this->transport->batches[] = $events;
  if ($pumpFirst) { $this->client->pump(); }
  $polls = $this->transport->polls;
  $input = new RendererInputSource($this->client);
  $this->presentation->present($this->snapshot);
  unset($this->presentation);
  expect($this->transport->polls)->toBe($polls)->and($this->transport->shutdowns)->toBe(0)
    ->and($input->poll())->toBe(KeyCode::UP)
    ->and($this->client->pollEvents())->toBe([$events[0], $events[2], $events[3]]);
})->with([false, true]);

it('fails explicitly on sequence exhaustion while still suppressing an identical frame', function () {
  $this->presentation->present($this->snapshot);
  new ReflectionProperty(RendererPresentation::class, 'frameNumber')->setValue($this->presentation, PHP_INT_MAX);
  expect($this->presentation->present($this->snapshot))->toBeFalse();
  expect(fn() => $this->presentation->present(new ConsoleFrameSnapshot(4, 2, ['new ', '    '])))
    ->toThrow(OverflowException::class);
  expect($this->transport->sent)->toHaveCount(1);
});
