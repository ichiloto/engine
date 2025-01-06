<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Exception;
use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MenuItem;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/**
 * QuitGameCommand is a command that quits the game.
 *
 * @package Ichiloto\Engine\Core\Menu\Commands
 */
class QuitGameCommand extends MenuItem
{
  /**
   * @inheritDoc
   */
  public function __construct(MenuInterface $menu)
  {
    parent::__construct($menu, config(ProjectConfig::class, 'vocab.game.shutdown') ?? 'Exit', 'Close the game application.', '');
  }

  /**
   * @inheritDoc
   * @throws Exception
   */
  public function execute(?ExecutionContextInterface $context = null): int
  {
    assert($context instanceof MenuCommandExecutionContext);
    $context->game->quit();

    return self::SUCCESS;
  }
}