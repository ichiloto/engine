<?php

namespace Ichiloto\Engine\Field;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\GameObject;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Actions\FieldActionContext;
use Ichiloto\Engine\Entities\Interfaces\ActionInterface;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Events\Enumerations\MovementEventType;
use Ichiloto\Engine\Events\MovementEvent;
use Ichiloto\Engine\Events\Triggers\EventTrigger;
use Ichiloto\Engine\Events\Triggers\EventTriggerContext;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Exceptions\OutOfBounds;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\UI\Elements\LocationHUDWindow;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
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
   * @var string $actionSprite The sprite of the player when performing an action.
   */
  protected string $actionSprite = '!';
  /**
   * @var MovementHeading $heading The heading of the player.
   */
  protected(set) MovementHeading $heading = MovementHeading::NONE;
  /**
   * @var bool $canShowLocationHUDWindow Determines whether the location HUD window can be shown.
   */
  protected bool $canShowLocationHUDWindow = false;
  /**
   * @var ItemList<EventTrigger> $events The list of events.
   */
  protected ItemList $events;
  /**
   * @var bool $canAct Determines whether the player can act.
   */
  public bool $canAct {
    get {
      return $this->availableAction !== null;
    }
  }
  /**
   * @var ActionInterface|null $availableAction The available action.
   */
  public ?ActionInterface $availableAction = null;
  /**
   * @var Vector2 $screenPosition The screen position of the player.
   */
  public Vector2 $screenPosition {
    get {
      $screenPosition = $this->position;
      $screenPosition->subtract($this->scene->camera->position);
      return $screenPosition;
    }
  }

  /**
   * Player constructor.
   *
   * @param SceneInterface $scene The scene.
   * @param string $name The name of the player.
   * @param Vector2 $position The position of the player.
   * @param Rect $shape The shape of the player.
   * @param array $sprite The sprite of the player.
   * @param MovementHeading $heading The heading of the player.
   */
  public function __construct(
    SceneInterface $scene,
    string $name,
    Vector2 $position,
    Rect $shape,
    array $sprite,
    MovementHeading $heading = MovementHeading::NONE
  )
  {
    parent::__construct(
      $scene,
      $name,
      $position,
      $shape,
      $sprite
    );
    $this->heading = $heading;
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function activate(): void
  {
    parent::activate();
    $this->canShowLocationHUDWindow = config(ProjectConfig::class, 'ui.hud.location', false);

    if (!$this->canShowLocationHUDWindow) {
      $this->getLocationHUDWindow()->erase();
    }

    $this->events = new ItemList(EventTrigger::class);
  }

  /**
   * Movement speed of the player.
   *
   * @param Vector2 $direction The direction to move to.
   * @param Camera $camera The camera.
   * @return void
   * @throws NotFoundException If the scene is not set.
   * @throws OutOfBounds If the player is out of bounds.
   */
  public function move(Vector2 $direction, Camera $camera): void
  {
    $origin = $this->position;
    $destination = Vector2::sum($origin, $direction);
    $collisionType = null;
    $this->updatePlayerSprite($direction);

    if (! $this->getGameScene()->mapManager->canMoveTo(intval($destination->x), intval($destination->y), $collisionType) ) {
      $this->render();
      return;
    }

    $event = new MovementEvent(MovementEventType::PLAYER_MOVE, $origin, $destination);
    $this->handleCollision($collisionType);
    $this->updatePlayerPosition($direction, $camera);
    $this->handleTriggers($event);


    if ($this->getGameScene()->mapManager->isAtSavePoint) {
      alert("Access the Menu to save your progress.", 'Save Point');
    }
    $this->notify($this->getGameScene(), $event);
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
      $this->getGameScene()->mapManager->isAtSavePoint = false;
      return;
    }

    $this->getGameScene()->mapManager->isAtSavePoint = ($collisionType === CollisionType::SAVE_POINT);
  }

  /**
   * Handles the triggers.
   *
   * @param MovementEvent $movementEvent The movement event.
   * @return void
   */
  protected function handleTriggers(MovementEvent $movementEvent): void
  {
    $eventTriggerContext = new EventTriggerContext(
      $movementEvent,
      $this->position,
      $this,
      $this->getGameScene(),
      $this->getGameScene()->mapManager
    );
    /** @var EventTrigger $event */
    foreach ($this->events as $event) {
      if ($event->isComplete) {
        continue;
      }

      if ( $event->area->contains($movementEvent->destination) ) {
        if (! $this->eventManager->activeEvents->contains($event)) {
          $this->eventManager->activeEvents->add($event);
          $event->enter($eventTriggerContext);
        } else {
          $event->stay($eventTriggerContext);
        }
      } else {
        if ($this->eventManager->activeEvents->contains($event)) {
          $event->exit($eventTriggerContext);
          $this->eventManager->activeEvents->remove($event);
        }
      }
    }
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
   * @param MapTrigger|EventTrigger $trigger The trigger to add.
   */
  public function addTrigger(MapTrigger|EventTrigger $trigger): void
  {
    if ($trigger instanceof MapTrigger) {
      $this->addObserver($trigger);
    }

    if ($trigger instanceof EventTrigger) {
      $this->events->add($trigger);
    }
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

    foreach ($this->events as $event) {
      $this->events->remove($event);
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
   * Updates the player position.
   *
   * @param Vector2 $direction The direction.
   * @param Camera $camera The camera.
   * @return void
   */
  protected function updatePlayerPosition(Vector2 $direction, Camera $camera): void
  {
    $mapManager = $this->getGameScene()->mapManager;
    $this->erasePlayer($camera);
    $this->position->add($direction);
    if ( $mapManager->scrollMap($this, $direction) ) {
      $mapManager->render();
    }
    $this->render();
    $this->renderLocationHUDWindow($direction);
  }

  /**
   * Renders the location HUD window.
   *
   * @param Vector2 $direction The direction.
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

  /**
   * Removes all event triggers.
   *
   * @return void
   */
  public function removeEventTriggers(): void
  {
    $this->events->clear();
  }

  /**
   * @inheritDoc
   */
  public function render(): void
  {
    parent::render();

    if ($this->canAct) {
      $this->scene->camera->draw($this->actionSprite, $this->screenPosition->x, clamp($this->screenPosition->y - 1, 1, get_screen_height()));
    }
  }

  public function renderPlayer(?Vector2 $offset = null): void
  {
    $x = $this->position->x - ($offset?->x ?? 0);
    $y = $this->position->y - ($offset?->y ?? 0);

    for ($row = $this->shape->getY(); $row < $this->shape->getY() + $this->shape->getHeight(); $row++) {
      $output = substr($this->sprite[$row], $this->shape->getX(), $this->shape->getWidth());
      $this->scene->camera->draw($output, $x, $y + $row);
    }

    if ($this->canAct) {
      $this->scene->camera->draw($this->actionSprite, $x, clamp($y - 1, 1, get_screen_height()));
    }
  }

  /**
   * @inheritDoc
   */
  public function erase(): void
  {
    parent::erase();

    if ($this->canAct) {
      $this->scene->renderBackgroundTile($this->position->x, clamp($this->position->y - 1, 1, get_screen_height()));
    }
  }

  /**
   * Erases the player.
   *
   * @param Camera $camera
   * @return void
   */
  public function erasePlayer(Camera $camera): void
  {
    for($row = $this->shape->getY(); $row < $this->shape->getY() + $this->shape->getHeight(); $row++) {
      $this->scene->renderBackgroundTile($this->position->x, $this->position->y + $row);
    }

    if ($this->canAct) {
      $this->scene->renderBackgroundTile($this->position->x, clamp($this->position->y - 1, 1, get_screen_height()));
    }
  }

  /**
   * Performs an action.
   *
   * @return void
   */
  public function interact(): void
  {
    $this->availableAction?->execute(new FieldActionContext(
      $this,
      $this->getGameScene(),
      $this->position
    ));
  }
}