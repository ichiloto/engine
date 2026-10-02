<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Battle\BattleCommandType;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\States\StateDisposition;

enum BattlePoseRole: string
{
  case IDLE = 'idle';
  case ATTACK = 'attack';
  case SKILL = 'skill';
  case MAGIC = 'magic';
  case SUMMON = 'summon';
  case PETITION = 'petition';
  case ITEM = 'item';
  case GUARD = 'guard';
  case DAMAGE = 'damage';
  case HEAL = 'heal';
  case AFFLICTED = 'afflicted';
  case ENHANCED = 'enhanced';
  case KNOCKOUT = 'knockout';

  public static function getForAction(?BattleAction $action): self
  {
    return match (BattleCommandCatalog::getActionType($action)) {
      BattleCommandType::ATTACK => self::ATTACK,
      BattleCommandType::MAGIC => self::MAGIC,
      BattleCommandType::SUMMON => self::SUMMON,
      BattleCommandType::ITEM => self::ITEM,
      BattleCommandType::GUARD => self::GUARD,
      BattleCommandType::SKILL, BattleCommandType::ESCAPE => self::SKILL,
    };
  }

  public static function getRestingRole(CharacterInterface $battler): self
  {
    return match (true) {
      $battler->isKnockedOut => self::KNOCKOUT,
      ($battler->isGuarding ?? false) => self::GUARD,
      method_exists($battler, 'getStateDisposition') && $battler->getStateDisposition() === StateDisposition::HARMFUL => self::AFFLICTED,
      method_exists($battler, 'getActionBlockingState') && $battler->getActionBlockingState() !== null => self::AFFLICTED,
      method_exists($battler, 'hasNegativeStatStage') && $battler->hasNegativeStatStage() => self::AFFLICTED,
      method_exists($battler, 'getStateDisposition') && $battler->getStateDisposition() === StateDisposition::BENEFICIAL => self::ENHANCED,
      method_exists($battler, 'hasPositiveStatStage') && $battler->hasPositiveStatStage() => self::ENHANCED,
      default => self::IDLE,
    };
  }
}
