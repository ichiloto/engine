<?php

namespace Ichiloto\Engine\Core\Menu\Interfaces;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Core\Interfaces\CanActivate;
use Ichiloto\Engine\Core\Interfaces\CanRenderAt;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;
use Ichiloto\Engine\Events\Interfaces\SubjectInterface;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\UI\Interfaces\SelectableInterface;

/**
 * Interface MenuInterface. Represents a menu.
 *
 * @package Ichiloto\Engine\Core\Menu\Interfaces
 */
interface MenuInterface extends CanUpdate, CanRenderAt, CanActivate, SubjectInterface, SelectableInterface
{
  /**
   * Returns the scene.
   *
   * @return SceneInterface The scene.
   */
  public function getScene(): SceneInterface;
  /**
   * Returns the title of the menu.
   *
   * @return string The title of the menu.
   */
  public function getTitle(): string;

  /**
   * Sets the title of the menu.
   *
   * @param string $title The title of the menu.
   * @return void
   */
  public function setTitle(string $title): void;

  /**
   * Returns the description of the menu.
   *
   * @return string The description of the menu.
   */
  public function getDescription(): string;

  /**
   * Sets the description of the menu.
   *
   * @param string $description The description of the menu.
   * @return void
   */
  public function setDescription(string $description): void;

  /**
   * Returns a list of items in the menu.
   *
   * @return ItemList The list of items in the menu.
   */
  public function getItems(): ItemList;

  /**
   * Sets the list of items in the menu.
   *
   * @param ItemList $items The list of items in the menu.
   * @return self The menu with the items set.
   */
  public function setItems(ItemList $items): self;

  /**
   * Adds an item to the menu.
   *
   * @param MenuItemInterface $item The item to add to the menu.
   * @return self The menu with the item added.
   */
  public function addItem(MenuItemInterface $item): self;

  /**
   * Removes an item from the menu.
   *
   * @param MenuItemInterface $item The item to remove from the menu.
   * @return self The menu with the item removed.
   */
  public function removeItem(MenuItemInterface $item): self;

  /**
   * Removes an item from the menu by its index.
   *
   * @param int $index The index of the item to remove from the menu.
   * @return self The menu with the item removed.
   */
  public function removeItemByIndex(int $index): self;

  /**
   * Returns an item from the menu by its index.
   *
   * @param int $index The index of the item to return.
   * @return MenuItemInterface The item with the specified index.
   */
  public function getItemByIndex(int $index): MenuItemInterface;

  /**
   * Returns an item from the menu by its label.
   *
   * @param string $label The label of the item to return.
   * @return MenuItemInterface|null The item with the specified label.
   */
  public function getItemByLabel(string $label): ?MenuItemInterface;

  /**
   * Returns the active item.
   *
   * @return MenuItemInterface The active item.
   */
  public function getActiveItem(): MenuItemInterface;

  /**
   * Sets the active item.
   *
   * @param MenuItemInterface $item The item to set as active.
   * @return void
   */
  public function setActiveItem(MenuItemInterface $item): void;

  public int $activeIndex {
    get;
  }

  /**
   * Sets the active item by its index.
   *
   * @param int $index The index of the item to set as active.
   * @return void
   */
  public function setActiveItemByIndex(int $index): void;

  /**
   * Sets the active item by its label.
   *
   * @param string $label The label of the item to set as active.
   * @return void
   */
  public function setActiveItemByLabel(string $label): void;
}