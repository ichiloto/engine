<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Exception;
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
  protected MenuInterface $menu;

  public function __construct(MenuInterface $menu)
  {
    $label = config(ProjectConfig::class, 'vocab.command.quit_game', 'Quit');
    parent::__construct($menu, $label, "Quit the game.");
  }

  /**
   * @inheritDoc
   * @throws Exception If the 'confirm' modal cannot be displayed or quit command fails.
   */
  public function execute(?ExecutionContextInterface $context = null): int
  {
    $quitVerb = config(ProjectConfig::class, 'vocab.verb.quit', 'quit');
    if (confirm("Are you sure you want to $quitVerb?", '', 40)) {
      $this->menu->getScene()->getGame()->quit();
    }
    return self::SUCCESS;
  }
}