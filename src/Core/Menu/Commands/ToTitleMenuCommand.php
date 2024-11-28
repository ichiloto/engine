<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MenuItem;
use Ichiloto\Engine\Scenes\Title\TitleScene;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/**
 * ToTitleMenuCommand. This class represents a menu item that returns to the title menu.
 *
 * @package Ichiloto\Engine\Core\Menu\Commands
 */
class ToTitleMenuCommand extends MenuItem
{
  /**
   * ToTitleMenuCommand constructor.
   *
   * @param MenuInterface $menu
   */
  public function __construct(MenuInterface $menu)
  {
    $label = config(ProjectConfig::class, 'vocab.command.to_title_menu', 'To Title');
    parent::__construct($menu, $label, "Return to the title menu.");
  }

  /**
   * @inheritDoc
   */
  public function execute(?ExecutionContextInterface $context = null): int
  {
    $this->menu->getScene()->getGame()->sceneManager->loadScene(TitleScene::class);
    return self::SUCCESS;
  }
}