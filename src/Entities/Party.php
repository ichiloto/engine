<?php

namespace Ichiloto\Engine\Entities;

/**
 * Class Party. Represents a party of characters in a battle.
 *
 * @package Ichiloto\Engine\Entities
 */
class Party extends BattleGroup
{
  /**
   * The maximum gold the party can have.
   */
  const int MAX_GOLD = 9999999;
  /**
   * The minimum gold the party can have.
   */
  const int MIN_GOLD = 0;

  /**
   * @var int The gold the party has.
   */
  protected(set) int $gold = 0 {
    get {
      return $this->gold;
    }

    set {
      $this->gold = clamp($value, self::MIN_GOLD, self::MAX_GOLD);
    }
  }
  /**
   * @var PartyLocation|null The party's location.
   */
  public ?PartyLocation $location = null;

  /**
   * Creates a new party from an array.
   *
   * @param array<string, mixed> $data The data to create the party from.
   */
  public static function fromArray(array $data): Party
  {
    $locationData = $data['location'] ?? [
      'name' => PartyLocation::DEFAULT_LOCATION_NAME,
      'region' => PartyLocation::DEFAULT_LOCATION_REGION
    ];
    $party = new Party();
    $party->location = new PartyLocation(
      $locationData['name'] ?? PartyLocation::DEFAULT_LOCATION_NAME,
      $locationData['region'] ?? PartyLocation::DEFAULT_LOCATION_REGION
    );

    foreach ($data as $datum) {
      $party->addMember(Character::fromArray($datum));
    }

    return $party;
  }
}