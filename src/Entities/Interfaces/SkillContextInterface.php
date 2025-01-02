<?php

namespace Ichiloto\Engine\Entities\Interfaces;

use Ichiloto\Engine\Entities\Character as User;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\GameSceneState;

/**
 * Represents the skill context interface.
 *
 * @package Ichiloto\Engine\Entities\Interfaces
 */
interface SkillContextInterface
{
  /**
   * @var User $user The user.
   */
  public User $user {
    get;
  }

  /**
   * @var User $target The target.
   */
  public User $target {
    get;
  }

  /**
   * @var GameScene $gameScene The scene.
   */
  public GameScene $gameScene {
    get;
  }

  /**
   * @var GameSceneState $gameSceneState The state.
   */
  public GameSceneState $gameSceneState {
    get;
  }

  /**
   * @var array<string, mixed> $args The arguments.
   */
  public array $args {
    get;
  }
}