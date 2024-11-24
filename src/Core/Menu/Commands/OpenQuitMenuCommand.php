<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MenuItem;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/**
 * OpenQuitMenuCommand. This class represents a menu item that opens the quit menu.
 *
 * @package Ichiloto\Engine\Core\Menu\Commands
 */
class OpenQuitMenuCommand extends MenuItem
{
  public function __construct(MenuInterface $menu)
  {
    $label = config(ProjectConfig::class, 'vocab.command.quit_game', 'Quit');
    parent::__construct($menu, $label, "Quit the game.");
  }

  /**
   * @inheritDoc
   */
  public function execute(?ExecutionContextInterface $context = null): int
  {
    // TODO: Implement execute() method.
    return self::SUCCESS;
  }
}