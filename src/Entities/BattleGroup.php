<?php

namespace Ichiloto\Engine\Entities;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Entities\Interfaces\GroupInterface;
use Ichiloto\Engine\Entities\Character as Battler;

/**
 * Class BattleGroup. Represents a group of battlers in a battle.
 *
 * @package Ichiloto\Engine\Entities
 */
abstract class BattleGroup implements GroupInterface
{
  /**
   * The members of the group.
   *
   * @var ItemList<Battler> $members
   */
  public ItemList $members {
    get {
      return $this->members;
    }
  }

  /**
   * Creates a new battle group.
   *
   * @param array<string, mixed> $config The configuration options for the battle group.
   */
  public function __construct(array $config = [])
  {
    $this->members = new ItemList(Battler::class);
    $this->configure($config);
  }

  /**
   * @inheritDoc
   */
  public function addMember(Battler $character): void
  {
    $this->members->add($character);
  }

  /**
   * @inheritDoc
   */
  public function isDefeated(): bool
  {
    return array_all($this->members->toArray(), fn($member) => $member->isDefeated());
  }
}