<?php

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;

/**
 * Describes a selectable entry inside a battle secondary command menu.
 *
 * @package Ichiloto\Engine\Battle
 */
readonly class BattleCommandOption
{
  /**
   * @param string $label The text shown in the submenu.
   * @param string $description The descriptive text for the option.
   * @param BattleAction $action The action to queue when selected.
   * @param ItemScopeSide $targetSide The target side this action expects.
   * @param ItemScopeStatus $targetStatus The target status this action expects.
   * @param mixed $source The original source object backing the action.
   */
  public function __construct(
    public string $label,
    public string $description,
    public BattleAction $action,
    public ItemScopeSide $targetSide = ItemScopeSide::ENEMY,
    public ItemScopeStatus $targetStatus = ItemScopeStatus::ALIVE,
    public mixed $source = null,
  )
  {
  }
}
