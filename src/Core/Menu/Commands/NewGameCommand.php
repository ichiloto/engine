<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MenuItem;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Scenes\Game\GameScene;

/**
 * NewGameCommand is a command that starts a new game.
 *
 * @package Ichiloto\Engine\Core\Menu\Commands
 */
class NewGameCommand extends MenuItem
{
  public function __construct(MenuInterface $menu)
  {
    parent::__construct($menu, 'New Game', 'Start a new game.', '');
  }

  /**
   * @inheritDoc
   * @throws NotFoundException
   */
  public function execute(?ExecutionContextInterface $context = null): int
  {
    assert($context instanceof MenuCommandExecutionContext);
    $context->getSceneManager()->loadScene(GameScene::class);
    return self::SUCCESS;
  }
}