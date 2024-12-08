<?php

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\GameObject;
use Ichiloto\Engine\Core\Interfaces\CanCompare;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Events\Enumerations\MovementEventType;
use Ichiloto\Engine\Events\MovementEvent;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Exceptions\OutOfBounds;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\UI\LocationHUDWindow;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Override;
use RuntimeException;

/**
 * Class Player. Represents a player in the game.
 *
 * @package Ichiloto\Engine\Field
 */
class Player extends GameObject
{
  /**
   * @var string $upSprite The sprite of the player when facing up.
   */
  protected string $upSprite = '^';
  /**
   * @var string $downSprite The sprite of the player when facing down.
   */
  protected string $downSprite = 'v';
  /**
   * @var string $rightSprite The sprite of the player when facing right.
   */
  protected string $rightSprite = '>';
  /**
   * @var string $leftSprite The sprite of the player when facing left.
   */
  protected string $leftSprite = '<';
  /**
   * @var MovementHeading $heading The heading of the player.
   */
  protected(set) MovementHeading $heading = MovementHeading::NONE;
  /**
   * @var bool $canShowLocationHUDWindow Determines whether the location HUD window can be shown.
   */
  protected bool $canShowLocationHUDWindow = false;

  #[Override]
  public function activate(): void
  {
    parent::activate();
    $this->canShowLocationHUDWindow = config(ProjectConfig::class, 'ui.hud.location', false);

    if (!$this->canShowLocationHUDWindow) {
      $this->getLocationHUDWindow()->erase();
    }
  }

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
    $origin = $this->position;
    $destination = Vector2::sum($origin, $direction);
    $collisionType = null;
    $this->updatePlayerSprite($direction);

    if (! $this->getGameScene()->mapManager->canMoveTo(intval($destination->x), intval($destination->y), $collisionType) ) {
      return;
    }

    $this->handleCollision($collisionType);
    $this->updatePlayerPosition($direction);

    $this->notify($this->getGameScene(), new MovementEvent(MovementEventType::PLAYER_MOVE, $origin, $destination));
  }

  /**
   * Returns the game scene.
   *
   * @return GameScene The game scene.
   */
  protected function getGameScene(): GameScene
  {
    if (! $this->scene instanceof GameScene) {
      throw new RuntimeException('The scene must be an instance of ' . GameScene::class);
    }

    return $this->scene;
  }

  /**
   * Handles the collision.
   *
   * @param CollisionType|null $collisionType The collision type.
   * @return void
   */
  protected function handleCollision(?CollisionType $collisionType): void
  {
    if (!$collisionType || $collisionType === CollisionType::NONE) {
      return;
    }

    $this->getGameScene()->mapManager->isAtSavePoint = $collisionType === CollisionType::SAVE_POINT;
  }

  /**
   * Returns the location HUD window.
   *
   * @return LocationHUDWindow The location HUD window.
   */
  protected function getLocationHUDWindow(): LocationHUDWindow
  {
    return $this->getGameScene()->locationHUDWindow;
  }

  /**
   * Adds a trigger to the observers' collection.
   *
   * @param MapTrigger $trigger The trigger to add.
   */
  public function addTrigger(MapTrigger $trigger): void
  {
    $this->addObserver($trigger);
  }

  /**
   * Removes all triggers from the observers collection.
   *
   * @return void
   */
  public function removeTriggers(): void
  {
    foreach ($this->observers as $observer) {
      if ($observer instanceof MapTrigger) {
        $this->observers->remove($observer);
      }
    }
  }

  /**
   * Updates the player sprite based on the direction.
   *
   * @param Vector2 $direction The direction.
   * @return void
   */
  public function updatePlayerSprite(Vector2 $direction): void
  {
    $this->sprite[0] = match (true) {
      $direction->y < 0 => $this->upSprite,
      $direction->y > 0 => $this->downSprite,
      $direction->x < 0 => $this->leftSprite,
      $direction->x > 0 => $this->rightSprite,
      default => $this->sprite[0],
    };
  }

  /**
   * @param Vector2 $direction
   * @return void
   */
  protected function updatePlayerPosition(Vector2 $direction): void
  {
    $this->erase();
    $this->position->add($direction);
    $this->render();
    $this->renderLocationHUDWindow($direction);
  }

  /**
   * @param Vector2 $direction
   * @return void
   */
  protected function renderLocationHUDWindow(Vector2 $direction): void
  {
    if ($this->canShowLocationHUDWindow) {
      $this->heading = match (true) {
        $direction->y < 0 => MovementHeading::NORTH,
        $direction->y > 0 => MovementHeading::SOUTH,
        $direction->x < 0 => MovementHeading::WEST,
        $direction->x > 0 => MovementHeading::EAST,
        default => MovementHeading::NONE,
      };
      $this->getLocationHUDWindow()->updateDetails($this->position, $this->heading);
    }
  }
}