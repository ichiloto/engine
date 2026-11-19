<?php

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Localization\Vocabulary;

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
   * @param int $mpCost The MP the acting character must hold to use this option.
   * @param ItemScopeNumber $targetNumber How many targets the action hits.
   * @param BattleCommandType|null $type Semantic presentation category, independent of the label.
   * @param int $apCost The AP the acting character must hold to use this option.
   */
  public function __construct(
    public string $label,
    public string $description,
    public BattleAction $action,
    public ItemScopeSide $targetSide = ItemScopeSide::ENEMY,
    public ItemScopeStatus $targetStatus = ItemScopeStatus::ALIVE,
    public mixed $source = null,
    public int $mpCost = 0,
    public ItemScopeNumber $targetNumber = ItemScopeNumber::ONE,
    public ?BattleCommandType $type = null,
    public int $apCost = 0,
  )
  {
  }

  public function isAffordable(?int $mp, ?int $ap): bool
  {
    return ($mp === null || $mp >= $this->mpCost) && ($ap === null || $ap >= $this->apCost);
  }

  public function getAffordabilityRefusal(CharacterInterface $actor): ?string
  {
    $stats = $actor->stats;
    $missing = match (true) {
      $stats->currentMp < $this->mpCost => [$this->mpCost, $stats->currentMp, Vocabulary::getTerm('stats.mp', 'MP')],
      $stats->currentAp < $this->apCost => [$this->apCost, $stats->currentAp, Vocabulary::getTerm('stats.ap')],
      default => null,
    };
    if ($missing === null) {
      return null;
    }

    [$cost, $balance, $resource] = $missing;
    return get_engine_message('battle.insufficient_resource_selection', $this->action->name, $cost,
      $actor->name, $balance, $resource);
  }
}
