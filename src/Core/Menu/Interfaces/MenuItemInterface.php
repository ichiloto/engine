<?php

namespace Ichiloto\Engine\Core\Menu\Interfaces;

use Ichiloto\Engine\Core\Interfaces\CanEnable;
use Ichiloto\Engine\Core\Interfaces\CanExecute;
use Stringable;

/**
 * MenuItemInterface is an interface implemented by all classes that can be menu items.
 *
 * @package Ichiloto\Engine\Core\Menu\Interfaces
 */
interface MenuItemInterface extends Stringable, CanExecute, CanEnable
{
  /**
   * Returns the label of the menu item.
   *
   * @return string The label of the menu item.
   */
  public function getLabel(): string;

  /**
   * Sets the label of the menu item.
   *
   * @param string $label The label of the menu item.
   * @return void
   */
  public function setLabel(string $label): void;

  /**
   * Returns the icon of the menu item.
   *
   * @return string The icon of the menu item.
   */
  public function getIcon(): string;

  /**
   * Sets the icon of the menu item.
   *
   * @param string $icon The icon of the menu item.
   * @return void
   */
  public function setIcon(string $icon): void;

  /**
   * Returns the description of the menu item.
   *
   * @return string The description of the menu item.
   */
  public function getDescription(): string;

  /**
   * Sets the description of the menu item.
   *
   * @param string $description The description of the menu item.
   * @return void
   */
  public function setDescription(string $description): void;

  /**
   * Returns whether the menu item is disabled.
   *
   * @return bool Whether the menu item is disabled.
   */
  public function isDisabled(): bool;
}