<?php

namespace Ichiloto\Engine\Entities\Magic;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;

/**
 * Represents the requirements and costs involved in learning a spell.
 *
 * Experience and training time act as thresholds, while gold and item costs
 * are consumed when the spell is learned.
 *
 * @package Ichiloto\Engine\Entities\Magic
 */
class SpellLearningRequirement
{
  /**
   * @param int $experienceRequired The required character experience threshold.
   * @param int $trainingHoursRequired The required training-time progress.
   * @param int $goldCost The gold cost paid on learning.
   * @param array<string, int> $itemCosts The required item costs keyed by item name.
   */
  public function __construct(
    public int $experienceRequired = 0,
    public int $trainingHoursRequired = 0,
    public int $goldCost = 0,
    public array $itemCosts = [],
  )
  {
  }

  /**
   * Creates a requirement instance from serialized data.
   *
   * @param array<string, mixed> $data The serialized requirement data.
   * @return self The reconstructed requirement.
   */
  public static function fromArray(array $data): self
  {
    return new self(
      intval($data['experienceRequired'] ?? 0),
      intval($data['trainingHoursRequired'] ?? 0),
      intval($data['goldCost'] ?? 0),
      array_map('intval', $data['itemCosts'] ?? [])
    );
  }

  /**
   * Converts the requirement to a serializable array.
   *
   * @return array<string, mixed> The serialized requirement.
   */
  public function toArray(): array
  {
    return [
      'experienceRequired' => $this->experienceRequired,
      'trainingHoursRequired' => $this->trainingHoursRequired,
      'goldCost' => $this->goldCost,
      'itemCosts' => $this->itemCosts,
    ];
  }

  /**
   * Determines whether the requirement has been fully satisfied.
   *
   * @param Character $character The character learning the spell.
   * @param Party $party The party that may pay shared costs.
   * @param int $trainingHours The accumulated training progress.
   * @return bool True when the spell can be learned.
   */
  public function isSatisfiedBy(Character $character, Party $party, int $trainingHours): bool
  {
    if ($character->currentExp < $this->experienceRequired) {
      return false;
    }

    if ($trainingHours < $this->trainingHoursRequired) {
      return false;
    }

    if ($party->cannotAfford($this->goldCost)) {
      return false;
    }

    foreach ($this->itemCosts as $itemName => $quantity) {
      if ($party->inventory->getQuantityByName($itemName) < $quantity) {
        return false;
      }
    }

    return true;
  }

  /**
   * Spends the consumable costs associated with learning a spell.
   *
   * @param Party $party The party paying the spell-learning costs.
   * @return void
   */
  public function consumeCosts(Party $party): void
  {
    if ($this->goldCost > 0) {
      $party->debit($this->goldCost);
    }

    foreach ($this->itemCosts as $itemName => $quantity) {
      $party->inventory->consumeQuantity($itemName, $quantity);
    }
  }

  /**
   * Builds a short requirement summary for menu display.
   *
   * @param Character $character The character learning the spell.
   * @param Party $party The party that may pay shared costs.
   * @param int $trainingHours The current training-time progress.
   * @return string The requirement summary.
   */
  public function describeProgress(Character $character, Party $party, int $trainingHours): string
  {
    $parts = [];

    if ($this->experienceRequired > 0) {
      $parts[] = sprintf('EXP %d/%d', $character->currentExp, $this->experienceRequired);
    }

    if ($this->trainingHoursRequired > 0) {
      $parts[] = sprintf('Time %dh/%dh', $trainingHours, $this->trainingHoursRequired);
    }

    if ($this->goldCost > 0) {
      $parts[] = sprintf('Gold %d/%d', $party->accountBalance, $this->goldCost);
    }

    foreach ($this->itemCosts as $itemName => $quantity) {
      $parts[] = sprintf('%s %d/%d', $itemName, $party->inventory->getQuantityByName($itemName), $quantity);
    }

    return implode('  ', $parts);
  }
}
