<?php

namespace Ichiloto\Engine\Entities\Abilities;

use Ichiloto\Engine\Core\Enumerations\ChronoUnit;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;

/**
 * Represents the requirements and costs involved in attaining an ability.
 *
 * @package Ichiloto\Engine\Entities\Abilities
 */
class AbilityLearningRequirement
{
  /**
   * @param int $experienceRequired The required character experience threshold.
   * @param int $playTimeSecondsRequired The required elapsed play time.
   * @param int $goldCost The gold cost paid on learning.
   * @param array<string, int> $itemCosts The required item costs keyed by item name.
   * @param string[] $requiredEvents The required story-event flags.
   */
  public function __construct(
    public int $experienceRequired = 0,
    public int $playTimeSecondsRequired = 0,
    public int $goldCost = 0,
    public array $itemCosts = [],
    public array $requiredEvents = [],
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
    $itemCosts = is_array($data['itemCosts'] ?? null) ? $data['itemCosts'] : [];
    $requiredEvents = is_array($data['requiredEvents'] ?? null) ? $data['requiredEvents'] : [];

    return new self(
      intval($data['experienceRequired'] ?? 0),
      intval($data['playTimeSecondsRequired'] ?? $data['timeRequired'] ?? 0),
      intval($data['goldCost'] ?? 0),
      array_map('intval', $itemCosts),
      array_values(array_map('strval', array_filter($requiredEvents, 'is_string')))
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
      'playTimeSecondsRequired' => $this->playTimeSecondsRequired,
      'goldCost' => $this->goldCost,
      'itemCosts' => $this->itemCosts,
      'requiredEvents' => $this->requiredEvents,
    ];
  }

  /**
   * Determines whether the requirement has been fully satisfied.
   *
   * @param Character $character The character learning the ability.
   * @param Party $party The party that may pay shared costs.
   * @param string[] $storyEvents The recorded story-event flags.
   * @param int $playTimeSeconds The elapsed play time in seconds.
   * @return bool True when the ability can be learned.
   */
  public function isSatisfiedBy(Character $character, Party $party, array $storyEvents = [], int $playTimeSeconds = 0): bool
  {
    if ($character->currentExp < $this->experienceRequired) {
      return false;
    }

    if ($playTimeSeconds < $this->playTimeSecondsRequired) {
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

    foreach ($this->requiredEvents as $eventName) {
      if (! in_array($eventName, $storyEvents, true)) {
        return false;
      }
    }

    return true;
  }

  /**
   * Spends the consumable costs associated with learning an ability.
   *
   * @param Party $party The party paying the learning costs.
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
   * @param Character $character The character learning the ability.
   * @param Party $party The party that may pay shared costs.
   * @param string[] $storyEvents The currently recorded story-event flags.
   * @param int $playTimeSeconds The elapsed play time in seconds.
   * @return string The requirement summary.
   */
  public function describeProgress(Character $character, Party $party, array $storyEvents = [], int $playTimeSeconds = 0): string
  {
    $parts = [];

    if ($this->experienceRequired > 0) {
      $parts[] = sprintf('EXP %d/%d', $character->currentExp, $this->experienceRequired);
    }

    if ($this->playTimeSecondsRequired > 0) {
      $parts[] = sprintf(
        'Time %s/%s',
        Time::formatDuration($playTimeSeconds, ChronoUnit::SECONDS),
        Time::formatDuration($this->playTimeSecondsRequired, ChronoUnit::SECONDS)
      );
    }

    if ($this->goldCost > 0) {
      $parts[] = sprintf('Gold %d/%d', $party->accountBalance, $this->goldCost);
    }

    foreach ($this->itemCosts as $itemName => $quantity) {
      $parts[] = sprintf('%s %d/%d', $itemName, $party->inventory->getQuantityByName($itemName), $quantity);
    }

    foreach ($this->requiredEvents as $eventName) {
      $parts[] = sprintf(
        '%s %s',
        $eventName,
        in_array($eventName, $storyEvents, true) ? '[Done]' : '[Needed]'
      );
    }

    return implode('  ', $parts);
  }
}
