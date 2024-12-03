<?php

namespace Ichiloto\Engine\Entities;

/**
 * Class Party. Represents a party of characters in a battle.
 *
 * @package Ichiloto\Engine\Entities
 */
class Party extends BattleGroup
{
  public static function fromArray(array $data): Party
  {
    $party = new Party();

    foreach ($data as $datum) {
      $party->addMember(Character::fromArray($datum));
    }

    return $party;
  }
}