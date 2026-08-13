<?php

namespace Ichiloto\Engine\Quests;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Entities\Party;
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
   * @param string $revealedDescription More specific journal text shown after
   *   the reveal conditions hold.
   * @param array<int, array<string, mixed>> $revealConditions World conditions
   *   that reveal the more specific text.
   */
  public function __construct(
    protected(set) QuestObjectiveType $type,
    protected(set) string $target,
    protected(set) int $quantity = 1,
    protected(set) string $description = '',
    protected(set) string $revealedDescription = '',
    protected(set) array $revealConditions = [],
  )
  {
    $this->quantity = max(1, $this->quantity);

    if (trim($this->description) === '') {
      $this->description = $this->type->describe($this->target, $this->quantity);
    }

    $this->revealedDescription = trim($this->revealedDescription);
    $this->revealConditions = array_values(array_filter($this->revealConditions, is_array(...)));
  }

  /**
   * Resolves the journal text the player is currently allowed to know.
   *
   * The objective target remains stable for progress and save compatibility;
   * only its presentation changes as authored world conditions become true.
   * Omitting reveal data preserves the historical static description.
   *
   * @param GameState|null $gameState The current persistent world state.
   * @param Party|null $party The current party, for item-backed conditions.
   * @return string The spoiler-safe current description.
   */
  public function displayDescription(?GameState $gameState = null, ?Party $party = null): string
  {
    if (
      $this->revealedDescription === ''
      || $this->revealConditions === []
      || $gameState === null
      || ! WorldConditionEvaluator::allHold($this->revealConditions, $gameState, $party)
    ) {
      return $this->description;
    }

    return $this->revealedDescription;
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
      strval($data['revealedDescription'] ?? ''),
      is_array($data['revealConditions'] ?? null) ? $data['revealConditions'] : [],
    );
  }
}
