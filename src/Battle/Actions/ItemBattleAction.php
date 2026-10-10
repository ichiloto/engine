<?php

namespace Ichiloto\Engine\Battle\Actions;

use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\Resolution\CombatActionResult;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Battle\Resolution\CombatResourceChange;
use Ichiloto\Engine\Battle\Resolution\CombatTargetResult;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as Actor;
use Ichiloto\Engine\Entities\Effects\HPRecoveryEffect;
use Ichiloto\Engine\Entities\Effects\BaseEffect;
use Ichiloto\Engine\Entities\Effects\MPRecoveryEffect;
use Ichiloto\Engine\Entities\Effects\ResurrectionEffect;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Inventory\Inventory;
use Ichiloto\Engine\Entities\Inventory\Items\Item;

/**
 * Executes an inventory item as a battle action.
 *
 * @package Ichiloto\Engine\Battle\Actions
 */
class ItemBattleAction extends BattleAction implements ExecutionEligibility
{
  public ItemScope $targetScope {
    get {
      $scope = new ItemScope($this->item->scope->side, $this->item->scope->number,
        $this->item->scope->status, $this->item->scope->randomNumber);
      if ($scope->side !== ItemScopeSide::NONE) { return $scope; }

      // Legacy recovery items omitted a scope. Explicit authored scopes win.
      foreach ($this->item->effects as $effect) {
        if ($effect instanceof ResurrectionEffect) {
          $scope->side = ItemScopeSide::ALLY;
          $scope->status = ItemScopeStatus::DEAD;
          break;
        }
        if ($effect instanceof HPRecoveryEffect || $effect instanceof MPRecoveryEffect) {
          $scope->side = ItemScopeSide::ALLY;
          $scope->status = ItemScopeStatus::ALIVE;
          break;
        }
      }
      return $scope;
    }
  }

  /**
   * @param Item $item The inventory item represented by this action.
   * @param Inventory|null $inventory The inventory the item is drawn from.
   *   When provided, consumption goes through it so depleted stacks are
   *   removed; without one the item quantity is decremented on its own.
   */
  public function __construct(
    protected(set) Item $item,
    protected(set) ?Inventory $inventory = null,
  )
  {
    parent::__construct($item->name);
  }

  public function getExecutionRefusal(Actor $actor): ?string
  {
    if ($actor->isKnockedOut) {
      return sprintf('%s cannot act while knocked out.', $actor->name);
    }
    $quantity = $this->inventory?->getQuantityById($this->item->id) ?? $this->item->quantity;
    return $quantity < 1
      ? sprintf('%s cannot use %s: none left.', $actor->name, $this->name)
      : null;
  }

  /**
   * @inheritDoc
   */
  public function execute(Actor $actor, array $targets): void
  {
    if ($this->getExecutionRefusal($actor) !== null) {
      return;
    }

    // Spend through the resource owner before effects can change that inventory.
    if ($this->item->consumable && $this->item->effects !== []
      && array_any($targets, static fn($target): bool => $target instanceof Actor)) {
      $this->consumeOne();
    }
    $results = [];

    foreach ($targets as $target) {
      if (! $target instanceof Actor) {
        continue;
      }

      $change = new CombatResourceChange();
      $hits = [];
      foreach ($this->item->effects as $effect) {
        $hp = $target->stats->currentHp;
        $mp = $target->stats->currentMp;
        $previousResult = $effect instanceof BaseEffect ? $effect->lastResult : null;
        $effect->apply($target);
        $change = $change->accumulate(CombatResourceChange::measure($target, $hp, $mp));
        if ($effect instanceof BaseEffect && $effect->lastResult !== null && $effect->lastResult !== $previousResult) {
          $hits[] = $effect->lastResult;
        }
      }
      $results[] = new CombatTargetResult(CombatResolver::identity($target), $hits, resourceChange: $change);
    }

    $this->lastResult = new CombatActionResult('item.' . $this->item->id, $this->nextExecutionId(),
      CombatResolver::identity($actor), $results);
  }

  /**
   * Consumes a single unit of the item.
   *
   * Consuming through the inventory keeps stack bookkeeping in one place: it
   * decrements the quantity and drops the stack once it is depleted, so a
   * fully used item cannot linger as a phantom zero-quantity entry in the
   * field menus.
   *
   * @return void
   */
  protected function consumeOne(): void
  {
    if ($this->inventory !== null) {
      if (!$this->inventory->consumeReference($this->item->id, 1, 'consuming a battle item')) {
        throw new \LogicException('An eligible battle item could not be consumed from its inventory.');
      }
      return;
    }

    $this->item->quantity--;
  }
}
