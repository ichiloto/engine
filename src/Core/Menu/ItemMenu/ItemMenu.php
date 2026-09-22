<?php

namespace Ichiloto\Engine\Core\Menu\ItemMenu;

use Ichiloto\Engine\Core\Menu\Menu;
use Ichiloto\Engine\Entities\Inventory\Inventory;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Scenes\Game\GameScene;
use InvalidArgumentException;

/**
 * The ItemMenu class.
 *
 * @package Ichiloto\Engine\Core\Menu\ItemMenu
 */
class ItemMenu extends Menu
{
  /** The Use/Sort/Discard list never admits equipment or quest-critical key items.
   * @return list<InventoryItem>
   */
  public function getRegularItems(): array
  {
    return array_values(array_filter($this->inventory->items->toArray(),
      static fn(InventoryItem $item) => !$item->isKeyItem));
  }

  /**
   * @var Inventory The inventory of the party.
   */
  public Inventory $inventory {
    get {
      $gameScene = $this->scene;

      if (!$gameScene instanceof GameScene) {
        throw new InvalidArgumentException("The scene must be a game scene.");
      }

      return $gameScene->party->inventory;
    }
  }
  /**
   * @inheritDoc
   */
  public function activate(): void
  {
    // TODO: Implement activate() method.
  }

  /**
   * @inheritDoc
   */
  public function deactivate(): void
  {
    // TODO: Implement deactivate() method.
  }

  /**
   * @inheritDoc
   */
  public function render(?int $x = null, ?int $y = null): void
  {
    // TODO: Implement render() method.
  }

  /**
   * @inheritDoc
   */
  public function erase(?int $x = null, ?int $y = null): void
  {
    // TODO: Implement erase() method.
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    // TODO: Implement update() method.
  }
}
