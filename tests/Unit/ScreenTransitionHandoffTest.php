<?php

use Ichiloto\Engine\Rendering\ScreenTransition;
use Ichiloto\Engine\Rendering\ScreenTransitionPhase;
use Ichiloto\Engine\Rendering\ScreenTransitionTreatment;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;

require_once __DIR__ . '/../Fixtures/Rendering/ScreenTransitions.php';

final class TransitionHandoffRecorder
{
  public array $events = [];
  public array $canvases = [];
  public int $handoffs = 0;
  public int $cleanups = 0;
  public bool $ready = true;
  public bool $failHandoff = false;
  public bool $failPresent = false;
  public function __construct(public ScreenTransitionTreatment $treatment) {}
  public function present(ScreenTransitionPhase $phase, float $progress): void
  {
    if ($this->failPresent) { throw new RuntimeException('Presentation failed.'); }
    $this->events[] = $phase->value;
    $this->canvases[] = $this->treatment->compose($phase, $progress, 1350, 720);
  }
  public function handoff(): void
  {
    $this->handoffs++;
    $this->events[] = 'handoff';
    if ($this->failHandoff) { throw new RuntimeException('Load failed.'); }
  }
  public function isReady(): bool { return $this->ready; }
  public function cleanup(): void
  {
    $this->cleanups++;
    $this->events[] = 'cleanup';
  }
  public function start(bool $enabled = true): \Ichiloto\Engine\Rendering\ScreenTransitionSession
  {
    return ScreenTransition::startHandoff($this->treatment, $this->present(...), $this->handoff(...),
      $this->isReady(...), $this->cleanup(...), $enabled);
  }
}

beforeEach(function () {
  $this->previousConfig = ConfigStore::has(ProjectConfig::class) ? ConfigStore::get(ProjectConfig::class) : null;
  ConfigStore::put(ProjectConfig::class, new class extends ProjectConfig {
    protected function load(): array { return ['accessibility' => ['reducedMotion' => false]]; }
  });
});

afterEach(function () {
  if ($this->previousConfig === null) { ConfigStore::remove(ProjectConfig::class); }
  else { ConfigStore::put(ProjectConfig::class, $this->previousConfig); }
});

it('uses two different authored appearances with the same finite handoff lifecycle', function (bool $diagonal) {
  $recorder = new TransitionHandoffRecorder(new ScreenTransitionTreatment(getScreenTransitionFixture($diagonal)));
  $session = $recorder->start();
  expect($session->update(.05))->toBeFalse()->and($recorder->events)->toBe(['gather']);
  expect($session->update(.1))->toBeFalse()->and($recorder->events)->toBe(['gather', 'cover']);
  expect($session->update(.21))->toBeFalse()->and($recorder->events)->toBe(['gather', 'cover', 'hold'])
    ->and($recorder->handoffs)->toBe(0);
  $session->update(.08);
  expect($recorder->events)->toBe(['gather', 'cover', 'hold', 'handoff', 'hold'])
    ->and($session->phase)->toBe(ScreenTransitionPhase::REVEAL);
  $session->update(.19);
  expect(end($recorder->events))->toBe('reveal');
  expect($session->update(.19))->toBeTrue()->and($recorder->cleanups)->toBe(1)->and($recorder->handoffs)->toBe(1);
  $session->update(100);
  $session->cancel();
  $session->renderCurrentFrame();
  expect($recorder->cleanups)->toBe(1)->and($recorder->handoffs)->toBe(1)
    ->and($session->hasRenderedFrame())->toBeFalse();
})->with(['diagonal' => [true], 'vertical' => [false]]);

it('emits opaque cover before handoff and covers the incoming replacement even across skipped phases', function () {
  $recorder = new TransitionHandoffRecorder(new ScreenTransitionTreatment(getScreenTransitionFixture()));
  $session = $recorder->start();
  $session->update(30);
  expect($recorder->events)->toBe(['hold'])->and($recorder->handoffs)->toBe(0);
  $session->update(30);
  expect($recorder->events)->toBe(['hold', 'handoff', 'hold'])->and($recorder->handoffs)->toBe(1);
  foreach ($recorder->canvases as $canvas) {
    $safety = $canvas->composites[0]->operations[0]->data;
    expect($safety['type'])->toBe('fill')->and($safety['opacity'])->toBe(1.0)
      ->and($safety['masks'])->toBe([])
      ->and($safety['destination'])->toBe(['x' => 0.0, 'y' => 0.0, 'width' => 1600.0, 'height' => 900.0]);
  }
  expect($session->update(30))->toBeTrue()->and($recorder->cleanups)->toBe(1);
});

it('holds through delayed readiness without replaying the sweep or handoff', function () {
  $recorder = new TransitionHandoffRecorder(new ScreenTransitionTreatment(getScreenTransitionFixture()));
  $recorder->ready = false;
  $session = $recorder->start();
  $session->update(1);
  for ($i = 0; $i < 3; $i++) { $session->update(1); }
  expect($recorder->events)->toBe(['hold', 'handoff', 'hold', 'hold', 'hold'])
    ->and($recorder->handoffs)->toBe(1)->and($recorder->cleanups)->toBe(0);
  $recorder->ready = true;
  $session->update(0);
  $session->update(.38);
  expect($session->isComplete)->toBeTrue()->and($recorder->cleanups)->toBe(1);
});

it('resolves clock subtraction noise at nanosecond precision without adding a frame', function () {
  $recorder = new TransitionHandoffRecorder(new ScreenTransitionTreatment(getScreenTransitionFixture()));
  $session = $recorder->start();
  $session->update(.15);
  $session->update(.21 - 1e-12);
  expect($session->phase)->toBe(ScreenTransitionPhase::HOLD)->and($recorder->handoffs)->toBe(0);
  $session->update(.08 - 1e-12);
  expect($session->phase)->toBe(ScreenTransitionPhase::REVEAL)->and($recorder->handoffs)->toBe(1);
  $session->update(.38 - 1e-12);
  expect($session->isComplete)->toBeTrue()->and($recorder->cleanups)->toBe(1);
});

it('adds no animation delay under Off or reduced motion but still waits for readiness', function (bool $reduced) {
  ConfigStore::get(ProjectConfig::class)->set('accessibility.reducedMotion', $reduced);
  $recorder = new TransitionHandoffRecorder(new ScreenTransitionTreatment(getScreenTransitionFixture()));
  $session = $recorder->start($reduced);
  expect($session->update(0))->toBeTrue()->and($recorder->events)->toBe(['handoff', 'cleanup']);
  $recorder = new TransitionHandoffRecorder(new ScreenTransitionTreatment(getScreenTransitionFixture()));
  $recorder->ready = false;
  $session = $recorder->start($reduced);
  expect($session->update(0))->toBeFalse()->and($recorder->events)->toBe(['handoff']);
  $recorder->ready = true;
  expect($session->update(0))->toBeTrue()->and($recorder->events)->toBe(['handoff', 'cleanup']);
})->with(['Off' => [false], 'reduced motion' => [true]]);

it('pauses on focus loss, repaints without advancing, and releases cancellation exactly once', function () {
  $recorder = new TransitionHandoffRecorder(new ScreenTransitionTreatment(getScreenTransitionFixture()));
  $session = $recorder->start();
  $session->update(1);
  $session->pause();
  $session->update(100);
  $session->renderCurrentFrame();
  expect($recorder->events)->toBe(['hold', 'hold'])->and($recorder->handoffs)->toBe(0);
  $session->resume();
  $session->update(.08);
  $session->cancel();
  $session->cancel();
  expect($recorder->handoffs)->toBe(1)->and($recorder->cleanups)->toBe(1)
    ->and($session->phase)->toBe(ScreenTransitionPhase::CANCELLED);
});

it('releases owned state on presentation or load failure and retains the original error', function (bool $failPresent) {
  $recorder = new TransitionHandoffRecorder(new ScreenTransitionTreatment(getScreenTransitionFixture()));
  $session = $recorder->start();
  if ($failPresent) {
    $recorder->failPresent = true;
  } else {
    $session->update(1);
    $recorder->failHandoff = true;
  }
  expect(fn() => $session->update(1))->toThrow(RuntimeException::class, $failPresent ? 'Presentation failed.' : 'Load failed.');
  $session->cancel();
  expect($session->isComplete)->toBeTrue()->and($recorder->cleanups)->toBe(1);
})->with(['presentation' => [true], 'scene load' => [false]]);

it('clips the same authored treatment to different logical surfaces without renderer style branches', function () {
  $treatment = new ScreenTransitionTreatment(getScreenTransitionFixture());
  foreach ([[1350, 720], [720, 1350], [400, 300]] as [$width, $height]) {
    foreach ([ScreenTransitionPhase::COVER, ScreenTransitionPhase::HOLD, ScreenTransitionPhase::REVEAL] as $phase) {
      $canvas = $treatment->compose($phase, .5, $width, $height);
      expect([$canvas->width, $canvas->height])->toBe([$width, $height])
        ->and($canvas->composites[0]->destination->toArray())
        ->toBe(['x' => 0.0, 'y' => 0.0, 'width' => (float)$width, 'height' => (float)$height]);
    }
  }
});

it('refuses unbounded timing and translucent handoff brushes', function () {
  $data = getScreenTransitionFixture();
  $data['timings']['cover'] = INF;
  expect(fn() => new ScreenTransitionTreatment($data))->toThrow(InvalidArgumentException::class);
  $data = getScreenTransitionFixture();
  $data['coverBrush']['stops'][0]['opacity'] = .9;
  expect(fn() => new ScreenTransitionTreatment($data))->toThrow(InvalidArgumentException::class, 'fully opaque');
  $data = getScreenTransitionFixture();
  $data['width'] = 4096;
  $data['height'] = 4096;
  expect(fn() => new ScreenTransitionTreatment($data))->toThrow(InvalidArgumentException::class);
});

it('refuses missing, nonnumeric and duplicate tween paths rather than executing expressions', function () {
  foreach ([[['opacity'], ['opacity']], [['opacity', 'execute']], [['brush', 'type']], [['brush', 'color', 'r']]] as $paths) {
    $data = getScreenTransitionFixture();
    $data['phases']['gather'][0]['tweens'] = array_map(fn($path) => ['path' => $path, 'to' => 1], $paths);
    expect(fn() => new ScreenTransitionTreatment($data))->toThrow(InvalidArgumentException::class);
  }
});

it('keeps inspection side effect free and rejects invalid elapsed time without discarding ownership', function () {
  $recorder = new TransitionHandoffRecorder(new ScreenTransitionTreatment(getScreenTransitionFixture()));
  $session = $recorder->start();
  $before = serialize($recorder->treatment);
  $recorder->treatment->compose(ScreenTransitionPhase::REVEAL, .75, 1350, 720);
  expect(serialize($recorder->treatment))->toBe($before)->and($recorder->events)->toBe([]);
  expect(fn() => $session->update(NAN))->toThrow(InvalidArgumentException::class);
  expect($session->isComplete)->toBeFalse()->and($recorder->handoffs)->toBe(0);
  $session->cancel();
});
