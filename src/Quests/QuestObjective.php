<?php

namespace Ichiloto\Engine\Quests;

use InvalidArgumentException;

/**
 * One step of a quest.
 *
 * @package Ichiloto\Engine\Quests
 */
class QuestObjective
{
  /**
   * @param QuestObjectiveType $type The kind of moment this objective tracks.
   * @param string $target The objective target (NPC, item, enemy, map id, or flag name).
   * @param int $quantity The required count.
   * @param string $description The journal text; derived from the type when blank.
   */
  public function __construct(
    protected(set) QuestObjectiveType $type,
    protected(set) string $target,
    protected(set) int $quantity = 1,
    protected(set) string $description = '',
  )
  {
    $this->quantity = max(1, $this->quantity);

    if (trim($this->description) === '') {
      $this->description = $this->type->describe($this->target, $this->quantity);
    }
  }

  /**
   * Determines whether the objective's target matches the given name.
   *
   * @param string $name The name to compare.
   * @return bool True when the names match (case-insensitively).
   */
  public function matches(string $name): bool
  {
    return strcasecmp(trim($this->target), trim($name)) === 0;
  }

  /**
   * Hydrates an objective from its data-file entry.
   *
   * @param array<string, mixed> $data The objective entry.
   * @return self The objective.
   */
  public static function fromArray(array $data): self
  {
    $type = QuestObjectiveType::tryFrom(strval($data['type'] ?? ''))
      ?? throw new InvalidArgumentException(sprintf('Unknown quest objective type: %s', strval($data['type'] ?? '')));

    $target = trim(strval($data['target'] ?? ''));

    if ($target === '') {
      throw new InvalidArgumentException('Quest objectives require a target.');
    }

    return new self(
      $type,
      $target,
      intval($data['quantity'] ?? 1),
      strval($data['description'] ?? ''),
    );
  }
}
