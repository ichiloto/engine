<?php

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\GameObject;
use Ichiloto\Engine\Core\Interfaces\CanCompare;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Events\Interfaces\ObserverInterface;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Exceptions\OutOfBounds;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use RuntimeException;

/**
 * Class Player. Represents a player in the game.
 *
 * @package Ichiloto\Engine\Field
 */
class Player extends GameObject
{
  protected string $upSprite = '^';
  protected string $downSprite = 'v';
  protected string $rightSprite = '>';
  protected string $leftSprite = '<';

  /**
   * Movement speed of the player.
   *
   * @param Vector2 $direction The direction to move to.
   * @return void
   * @throws NotFoundException If the scene is not set.
   * @throws OutOfBounds If the player is out of bounds.
   */
  public function move(Vector2 $direction): void
  {
    $newPosition = Vector2::sum($this->position, $direction);
    $collisionType = null;

    if (! $this->getGameScene()->mapManager->canMoveTo(intval($newPosition->x), intval($newPosition->y), $collisionType) ) {
      return;
    }

    $this->handleCollision($collisionType);

    // Update sprite
    $this->sprite[0] = match (true) {
      $direction->y < 0 => $this->upSprite,
      $direction->y > 0 => $this->downSprite,
      $direction->x < 0 => $this->leftSprite,
      $direction->x > 0 => $this->rightSprite,
      default => $this->sprite[0],
    };

    $this->erase();
    $this->position->add($direction);
    $this->render();
  }

  /**
   * Returns the game scene.
   *
   * @return GameScene The game scene.
   */
  protected function getGameScene(): GameScene
  {
    if (! $this->scene instanceof GameScene) {
      throw new RuntimeException("The scene must be an instance of GameScene.");
    }

    return $this->scene;
  }

  protected function handleCollision(?CollisionType $collisionType): void
  {
    if (!$collisionType || $collisionType === CollisionType::NONE) {
      return;
    }
  }
}