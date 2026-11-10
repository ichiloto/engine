<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Performance;

use InvalidArgumentException;

/** A project-authored prompt, its choices in display order, and the matching choice. */
final readonly class PerformancePhrase
{
  /** @param list<string> $choices */
  public function __construct(
    public string $prompt,
    public array $choices,
    public int $matchingChoice,
  ) {
    if (trim($prompt) === '' || !array_is_list($choices) || !isset($choices[$matchingChoice])
      || !array_all($choices, static fn(mixed $choice): bool => is_string($choice) && trim($choice) !== '')) {
      throw new InvalidArgumentException('A performance phrase needs a prompt, ordered choices and a valid matching choice.');
    }
  }
}
