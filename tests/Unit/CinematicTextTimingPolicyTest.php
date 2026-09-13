<?php

use Ichiloto\Engine\Cutscenes\Cinematics\CinematicTextTimingPolicy;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;

final class CinematicTextTimingConfigStub implements ConfigInterface
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

beforeEach(function () {
  ConfigStore::remove(ProjectConfig::class);
});

it('holds narration for its visible reading time rather than a short authored timeout', function () {
  $text = 'Wind enters through all four restored road channels. The Stone sounds one low tone.';

  expect(round(CinematicTextTimingPolicy::narrationDuration($text, 1.2), 6))->toBe(5.666667)
    ->and(CinematicTextTimingPolicy::narrationDuration('A brief stillness.', 1.2))->toBe(3.0)
    ->and(CinematicTextTimingPolicy::narrationDuration('A brief stillness.', 6.0))->toBe(6.0);
});

it('counts only visible words and honours project narration timing policy', function () {
  ConfigStore::put(ProjectConfig::class, new CinematicTextTimingConfigStub([
    'ui' => ['cinematics' => ['narration' => [
      'minimum_duration' => 2.0,
      'words_per_minute' => 120.0,
      'settle_duration' => 0.5,
    ]]],
  ]));

  expect(CinematicTextTimingPolicy::narrationDuration(
    '<fg=yellow>One two three four</> five six seven eight',
    1.0,
  ))->toBe(4.5);
});
