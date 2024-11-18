<?php

namespace Ichiloto\Engine\Core\Menu\Interfaces;

use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;
use Ichiloto\Engine\UI\Interfaces\CanFocus;

/**
 * MenuManagerInterface is an interface implemented by all classes that manage menus.
 *
 * @package Ichiloto\Engine\Core\Menu\Interfaces
 */
interface MenuManagerInterface extends CanUpdate, CanRender, CanFocus
{
  /**
   * Determines if the menu is focused.
   *
   * @param MenuGraphNodeInterface $target
   * @return bool
   */
  public function isFocused(MenuGraphNodeInterface $target): bool;

  /**
   * Returns the focused node.
   *
   * @return MenuGraphNodeInterface|null
   */
  public function getFocused(): ?MenuGraphNodeInterface;
}