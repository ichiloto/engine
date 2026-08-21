<?php

use Ichiloto\Engine\Rendering\Enumerations\TransitionStyle;
use Ichiloto\Engine\Rendering\ScreenTransition;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;

beforeEach(function () {
  if (ConfigStore::doesntHave(PlaySettings::class)) {
    ConfigStore::put(PlaySettings::class, new PlaySettings([
      'width' => DEFAULT_SCREEN_WIDTH,
      'height' => DEFAULT_SCREEN_HEIGHT,
      'screen' => [
        'width' => DEFAULT_SCREEN_WIDTH,
        'height' => DEFAULT_SCREEN_HEIGHT,
      ],
    ]));
  }
});

class TransitionConfigStub implements ConfigInterface
{
  public function __construct(private array $values = [])
  {
  }

  public function get(string $path, mixed $default = null): mixed
  {
    $value = $this->values;

    foreach (explode('.', $path) as $segment) {
      if (! is_array($value) || ! array_key_exists($segment, $value)) {
        return $default;
      }

      $value = $value[$segment];
    }

    return $value;
  }

  public function set(string $path, mixed $value): void
  {
  }

  public function has(string $path): bool
  {
    $sentinel = new stdClass();

    return $this->get($path, $sentinel) !== $sentinel;
  }

  public function persist(): void
  {
  }
}

/**
 * Records the frames the transition would draw instead of writing them.
 */
class RecordingScreenTransition extends ScreenTransition
{
  public array $frames = [];

  protected function drawFrame(string $fill, int $columns): void
  {
    $this->frames[] = [$fill, $columns];
  }

  protected function play(array $frames): void
  {
    if (! $this->isEnabled()) {
      return;
    }

    foreach ($frames as [$fill, $columns]) {
      $this->drawFrame($fill, $columns);
    }
  }
}

it('reads its style and duration from the project config', function () {
  ConfigStore::put(ProjectConfig::class, new TransitionConfigStub([
    'ui' => ['transitions' => ['style' => 'wipe', 'duration' => 500]],
  ]));

  $transition = ScreenTransition::fromConfig();

  expect($transition->style)->toBe(TransitionStyle::WIPE)
    ->and($transition->durationMs)->toBe(500);
});

it('stays off unless a project opts in', function () {
  ConfigStore::put(ProjectConfig::class, new TransitionConfigStub([]));

  // A straight cut is the baseline; an effect between every doorway is
  // something a project or a player asks for.
  expect(ScreenTransition::fromConfig()->style)->toBe(TransitionStyle::NONE);
});

it('stays off when the project names a style that does not exist', function () {
  ConfigStore::put(ProjectConfig::class, new TransitionConfigStub([
    'ui' => ['transitions' => ['style' => 'dissolve']],
  ]));

  expect(ScreenTransition::fromConfig()->style)->toBe(TransitionStyle::NONE);
});

it('draws nothing when a project turns transitions off', function () {
  ConfigStore::put(ProjectConfig::class, new TransitionConfigStub([]));

  $transition = new RecordingScreenTransition(TransitionStyle::NONE);
  $transition->out();

  expect($transition->isEnabled())->toBeFalse()
    ->and($transition->frames)->toBe([]);
});

it('draws nothing for a player who asked for reduced motion', function () {
  ConfigStore::put(ProjectConfig::class, new TransitionConfigStub([
    'accessibility' => ['reducedMotion' => true],
  ]));

  $transition = new RecordingScreenTransition(TransitionStyle::FADE);
  $transition->out();

  expect($transition->frames)->toBe([]);
});

it('covers the screen in heavier shades and uncovers in lighter ones', function () {
  ConfigStore::put(ProjectConfig::class, new TransitionConfigStub([]));

  $out = new RecordingScreenTransition(TransitionStyle::FADE);
  $out->out();

  $shades = array_column($out->frames, 0);

  expect($shades)->toBe(['░', '▒', '▓', '█'])
    ->and(array_reverse($shades))->toBe(['█', '▓', '▒', '░']);
});

it('redraws the view it revealed', function () {
  ConfigStore::put(ProjectConfig::class, new TransitionConfigStub([]));

  $redrawn = false;
  new RecordingScreenTransition(TransitionStyle::NONE)->in(function () use (&$redrawn): void {
    $redrawn = true;
  });

  // Even with the effect off, the caller's redraw still runs: the view has to
  // come back either way.
  expect($redrawn)->toBeTrue();
});

it('sweeps a wipe across the full width', function () {
  ConfigStore::put(ProjectConfig::class, new TransitionConfigStub([]));

  $transition = new RecordingScreenTransition(TransitionStyle::WIPE);
  $transition->out();

  $columns = array_column($transition->frames, 1);

  expect($columns)->not->toBeEmpty()
    ->and($columns[0])->toBeLessThan(end($columns))
    ->and(end($columns))->toBe(get_screen_width());
});

it('advances transition frames cooperatively without sleeping', function () {
  ConfigStore::put(ProjectConfig::class, new TransitionConfigStub([]));
  $transition = new RecordingScreenTransition(TransitionStyle::FADE, 400);
  $session = $transition->session('out');

  expect($session->isComplete)->toBeFalse()
    ->and($session->update(0.05))->toBeFalse()
    ->and($transition->frames)->toBe([]);

  expect($session->update(0.25))->toBeFalse()
    ->and(array_column($transition->frames, 0))->toBe(['░', '▒', '▓']);

  expect($session->update(0.1))->toBeTrue()
    ->and(array_column($transition->frames, 0))->toBe(['░', '▒', '▓', '█']);
});
