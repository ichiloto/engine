<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Progression\Mastery;

use InvalidArgumentException;

/**
 * A skill the project keeps off the mastery tree for now, declared in `assets/Data/mastery.php` under `held`: the
 * author's decision that no node teaches it yet, and the note that records why, which authoring tools ask for. The
 * skill stays a complete record other parts of the project may still use.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final readonly class MasteryHeldSkill
{
  /**
   * @param string $skill The held skill's name.
   * @param string $note Why it is held, in the author's words; empty until the author records it.
   * @throws InvalidArgumentException When the skill is not named.
   */
  public function __construct(public string $skill, public string $note = '')
  {
    if (trim($skill) === '' || trim($skill) !== $skill) {
      throw new InvalidArgumentException('A held mastery skill names its skill.');
    }
  }

  /**
   * @param array<string, mixed> $data One `held` entry: `skill`, and optionally `note`.
   * @throws InvalidArgumentException When the entry does not name a skill, or holds anything else.
   */
  public static function fromArray(array $data): self
  {
    if (array_diff(array_keys($data), ['skill', 'note']) !== []) {
      throw new InvalidArgumentException('A held mastery skill holds only its skill and note.');
    }
    if (! is_string($data['skill'] ?? null) || ! is_string($data['note'] ?? '')) {
      throw new InvalidArgumentException('A held mastery skill names its skill, and its note is text.');
    }

    return new self($data['skill'], $data['note'] ?? '');
  }
}
