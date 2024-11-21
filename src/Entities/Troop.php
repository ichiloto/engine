<?php

namespace Ichiloto\Engine\Entities;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Interfaces\GroupInterface;

class Troop implements GroupInterface
{

  /**
   * @inheritDoc
   */
  public function addMember(Character $character): void
  {
    // TODO: Implement addMember() method.
  }

  /**
   * @inheritDoc
   */
  public function getMembers(): ItemList
  {
    // TODO: Implement getMembers() method.
  }

  /**
   * @inheritDoc
   */
  public function isDefeated(): bool
  {
    // TODO: Implement isDefeated() method.
  }
}