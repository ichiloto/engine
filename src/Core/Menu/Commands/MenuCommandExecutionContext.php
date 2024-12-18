<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\Scenes\SceneManager;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * MenuCommandExecutionContext is a class that represents the execution context of a menu command.
 *
 * @package Ichiloto\Engine\Core\Menu\Commands
 */
class MenuCommandExecutionContext implements ExecutionContextInterface
{
  /**
   * @var Game The game.
   */
  public Game $game {
    get {
      return $this->scene->getGame();
    }
  }

  /**
   * @var SceneManager The scene manager.
   */
  public SceneManager $sceneManager {
    get {
      return SceneManager::getInstance($this->game);
    }
  }

  /**
   * Creates a new menu command execution context instance.
   *
   * @param array<string, mixed> $args The arguments.
   * @param OutputInterface $output The output.
   * @param MenuInterface $menu The menu.
   * @param SceneInterface $scene The scene.
   */
  public function __construct(
    public array $args {
      get {
        return $this->args;
      }
    },
    public OutputInterface $output {
      get {
        return $this->output;
      }
    },
    public MenuInterface $menu {
      get {
        return $this->menu;
      }
    },
    public SceneInterface $scene {
      get {
        return $this->scene;
      }
    },
  )
  {
  }
}