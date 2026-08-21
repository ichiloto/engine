<?php

use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationDuration;
use Ichiloto\Engine\Messaging\Notifications\NotificationTimingPolicy;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;

final class NotificationTimingConfigStub implements ConfigInterface
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

it('uses the standard notification timing profile by default', function () {
  expect(NotificationTimingPolicy::duration(NotificationDuration::SHORT))->toBe(4.0)
    ->and(NotificationTimingPolicy::duration(NotificationDuration::MEDIUM))->toBe(6.0)
    ->and(NotificationTimingPolicy::duration(NotificationDuration::LONG))->toBe(8.0)
    ->and(NotificationTimingPolicy::animationDuration())->toBe(0.30);
});

it('applies project timing overrides and the player duration scale globally', function () {
  ConfigStore::put(ProjectConfig::class, new NotificationTimingConfigStub([
    'ui' => [
      'notifications' => [
        'durations' => ['medium' => 7.5],
        'animation_duration' => 0.45,
      ],
    ],
    'accessibility' => ['notificationDurationScale' => 2.0],
  ]));

  expect(NotificationTimingPolicy::duration(NotificationDuration::MEDIUM))->toBe(15.0)
    ->and(NotificationTimingPolicy::duration(3.5))->toBe(7.0)
    ->and(NotificationTimingPolicy::animationDuration())->toBe(0.45);
});

it('suppresses notification slides when reduced motion is enabled', function () {
  ConfigStore::put(ProjectConfig::class, new NotificationTimingConfigStub([
    'accessibility' => ['reducedMotion' => true],
    'ui' => ['notifications' => ['animation_duration' => 0.45]],
  ]));

  expect(NotificationTimingPolicy::animationDuration())->toBe(0.0)
    ->and(NotificationTimingPolicy::animationDuration(0.2))->toBe(0.0);
});
