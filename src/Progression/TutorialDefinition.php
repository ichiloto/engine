<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Progression;

use Ichiloto\Engine\Core\WorldConditionEvaluator;
use InvalidArgumentException;

/** A project-authored guide. The save retains progress under its stable id. */
final readonly class TutorialDefinition
{
  /**
   * @param list<array<string, mixed>> $trigger
   * @param non-empty-list<TutorialStep> $steps
   */
  private function __construct(
    public string $id,
    public string $category,
    public string $title,
    public array $trigger,
    public TutorialStartMode $start,
    public array $steps,
    public TutorialReplayMode $replay,
    public ?string $demo,
    public ?string $onFinish,
  ) {}

  public static function fromArray(mixed $data): self
  {
    if (!is_array($data) || array_diff(array_keys($data), ['id', 'category', 'title', 'trigger', 'start', 'steps', 'replay', 'demo', 'onFinish']) !== []) {
      throw new InvalidArgumentException('Expected a tutorial definition.');
    }

    self::assertText($data['id'] ?? null, 'Tutorial id');
    $source = 'Tutorial ' . $data['id'];
    self::assertText($data['category'] ?? null, $source . ' category');
    self::assertText($data['title'] ?? null, $source . ' title');
    $start = is_string($data['start'] ?? null) ? TutorialStartMode::tryFrom($data['start']) : null;
    $replay = is_string($data['replay'] ?? null) ? TutorialReplayMode::tryFrom($data['replay']) : null;
    if ($start === null || $replay === null) {
      throw new InvalidArgumentException($source . ' requires valid start and replay modes.');
    }

    $trigger = $data['trigger'] ?? [];
    if (!is_array($trigger) || !array_is_list($trigger) || ($start === TutorialStartMode::AUTOMATIC && $trigger === [])) {
      throw new InvalidArgumentException($source . ' requires a condition list, with at least one condition for an automatic start.');
    }
    WorldConditionEvaluator::validateAll($trigger, $source . ' trigger');

    $steps = $data['steps'] ?? null;
    if (!is_array($steps) || !array_is_list($steps) || $steps === []) {
      throw new InvalidArgumentException($source . ' requires at least one ordered step.');
    }
    $steps = array_map(
      static fn(mixed $step, int $index): TutorialStep => TutorialStep::fromArray($step, $source . ' step ' . ($index + 1)),
      $steps,
      array_keys($steps),
    );

    $demo = $data['demo'] ?? null;
    $onFinish = $data['onFinish'] ?? null;
    if ($demo !== null || $replay === TutorialReplayMode::DEMO) {
      self::assertText($demo, $source . ' demo id');
    }
    if ($onFinish !== null) {
      self::assertText($onFinish, $source . ' follow-on script id');
    }

    return new self($data['id'], $data['category'], $data['title'], $trigger, $start, $steps, $replay, $demo, $onFinish);
  }

  /** @phpstan-assert non-empty-string $value */
  public static function assertText(mixed $value, string $source): void
  {
    if (!is_string($value) || $value === '' || trim($value) !== $value) {
      throw new InvalidArgumentException($source . ' requires nonempty trimmed text.');
    }
  }
}
