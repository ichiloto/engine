<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Ichiloto\Engine\Core\Menu\MenuItem;

/**
 * LoadSceneCommand is a command that loads a scene.
 *
 * @package Ichiloto\Engine\Core\Menu\Commands
 */
class LoadSceneCommand extends MenuItem
{
  /**
   * Creates a new LoadSceneCommand instance.
   *
   * @param string $label The label of the menu item.
   * @param string $description The description of the menu item.
   * @param string $icon The icon of the menu item.
   * @param string|int $index The index of the scene to load.
   */
  public function __construct(
    string $label,
    string $description,
    string $icon = '',
    protected string|int $index = ''
  )
  {
    parent::__construct($label, $description, $icon);
  }
}