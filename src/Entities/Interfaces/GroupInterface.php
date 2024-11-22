<?php

namespace Ichiloto\Engine\Entities\Interfaces;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Entities\Character;

interface GroupInterface
{
  /**
   * Adds a member to the group.
   *
   * @param Character $character The member to add to the group.
   * @return void
   */
  public function addMember(Character $character): void;

  /**
   * Returns the members of the group.
   *
   * @return ItemList The members of the group.
   */
  public ItemList $members {
    get;
  }

  /**
   * Determines if the group is defeated.
   *
   * @return bool True if the group is defeated; otherwise, false.
   */
  public function isDefeated(): bool;
}