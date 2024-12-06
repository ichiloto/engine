<?php

namespace Ichiloto\Engine\Entities;

use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;

/**
 * The ItemScope class.
 *
 * @package Ichiloto\Engine\Entities
 */
class ItemScope
{
  /**
   * The ItemScope constructor.
   *
   * @param ItemScopeSide $side The side.
   * @param ItemScopeNumber $number The number.
   * @param ItemScopeStatus $status The status.
   */
  public function __construct(
    public ItemScopeSide $side = ItemScopeSide::ENEMY,
    public ItemScopeNumber $number = ItemScopeNumber::ONE,
    public ItemScopeStatus $status = ItemScopeStatus::ALIVE,
    public ?int $targetCount = null,
  )
  {
  }
}