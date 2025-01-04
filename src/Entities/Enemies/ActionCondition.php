<?php

namespace Ichiloto\Engine\Entities\Enemies;

use Ichiloto\Engine\Core\Range;
use Ichiloto\Engine\Entities\Enumerations\ActionConditionType;

/**
 * Represents an action condition.
 *
 * @package Ichiloto\Engine\Entities\Enemies
 */
class ActionCondition
{
  /**
   * ActionCondition constructor.
   *
   * @param ActionConditionType $type
   * @param Range $range
   * @param int $a
   * @param int $b
   * @param mixed $status
   * @param int $partyLevel
   */
  public function __construct(
    protected(set) ActionConditionType $type = ActionConditionType::ALWAYS,
    protected(set) Range $range = new Range(0, 100),
    protected(set) int $a = 0,
    protected(set) int $b = 0,
    protected(set) mixed $status = null,
    protected(set) int $partyLevel = 1, // or above
  )
  {
  }
}