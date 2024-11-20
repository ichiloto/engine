<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * MenuCommandExecutionContext is a class that represents the execution context of a menu command.
 *
 * @package Ichiloto\Engine\Core\Menu\Commands
 */
class MenuCommandExecutionContext implements ExecutionContextInterface
{
  /**
   * Creates a new menu command execution context instance.
   *
   * @param array<string, mixed> $args The arguments.
   * @param OutputInterface $output The output.
   */
  public function __construct(
    protected array $args,
    protected OutputInterface $output,
    protected MenuInterface $menu,
    protected SceneInterface $scene,
  )
  {
  }

  /**
   * @inheritDoc
   */
  public function getArgs(): array
  {
    return $this->args;
  }

  /**
   * @inheritDoc
   */
  public function getOutput(): OutputInterface
  {
    return $this->output;
  }

  /**
   * Return the menu
   *
   * @return MenuInterface The menu.
   */
  public function getMenu(): MenuInterface
  {
    return $this->menu;
  }

  /**
   * Return the scene
   *
   * @return SceneInterface The scene.
   */
  public function getScene(): SceneInterface
  {
    return $this->scene;
  }

  /**
   * Return the game
   *
   * @return Game The game.
   */
  public function getGame(): Game
  {
    return $this->getScene()->getGame();
  }
}