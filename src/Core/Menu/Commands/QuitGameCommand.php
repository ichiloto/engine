<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\MenuItem;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/**
 * QuitGameCommand is a command that quits the game.
 *
 * @package Ichiloto\Engine\Core\Menu\Commands
 */
class QuitGameCommand extends MenuItem
{
  public function __construct()
  {
    parent::__construct(config(ProjectConfig::class, 'vocab.command.game_end') ?? 'Quit', 'Quit the game.', '');
  }

  /**
   * @inheritDoc
   */
  public function execute(?ExecutionContextInterface $context = null): int
  {
    assert($context instanceof MenuCommandExecutionContext);
    $context->getGame()->quit();

    return self::SUCCESS;
  }
}