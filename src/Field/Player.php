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
use Ichiloto\Engine\IO\Console\TerminalText;
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
   * @var string[] $upSprite The sprite of the player when facing up.
   */
  protected array $upSprite = ['^'];
  /**
   * @var string[] $downSprite The sprite of the player when facing down.
   */
  protected array $downSprite = ['v'];
  /**
   * @var string[] $rightSprite The sprite of the player when facing right.
   */
  protected array $rightSprite = ['>'];
  /**
   * @var string[] $leftSprite The sprite of the player when facing left.
   */
  protected array $leftSprite = ['<'];
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
      return $this->getRenderScreenPosition($this->position);
    }
  }

  /**
   * Player constructor.
   *
   * @param SceneInterface $scene The scene.
   * @param string $name The name of the player.
   * @param Vector2 $position The position of the player.
   * @param Rect $shape The shape of the player.
   * @param string[] $sprite The active sprite of the player.
   * @param MovementHeading $heading The heading of the player.
   * @param array<string, string[]> $directionalSprites The configured directional sprite set.
   */
  public function __construct(
    SceneInterface $scene,
    string $name,
    Vector2 $position,
    Rect $shape,
    array $sprite,
    MovementHeading $heading = MovementHeading::NONE,
    array $directionalSprites = []
  )
  {
    parent::__construct(
      $scene,
      $name,
      $position,
      $shape,
      $sprite
    );

    $this->configureDirectionalSprites($directionalSprites);
    $this->heading = $heading;
    $this->setFacingSprite($sprite, $heading);
    $this->canShowLocationHUDWindow = config(ProjectConfig::class, 'ui.hud.location', false);
    $this->events = new ItemList(EventTrigger::class);
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function activate(): void
  {
    $this->canShowLocationHUDWindow = config(ProjectConfig::class, 'ui.hud.location', false);

    if (!$this->canShowLocationHUDWindow) {
      $this->getLocationHUDWindow()->erase();
    }

    parent::activate();
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
    $previousSprite = $this->sprite;
    $this->updatePlayerSprite($direction);

    if (! $this->getGameScene()->mapManager->canMoveTo(intval($destination->x), intval($destination->y), $collisionType) ) {
      $this->render();
      return;
    }

    $event = new MovementEvent(MovementEventType::PLAYER_MOVE, $origin, $destination);
    $this->handleCollision($collisionType);
    $this->updatePlayerPosition($direction, $camera, $previousSprite);
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
    $this->heading = match (true) {
      $direction->y < 0 => MovementHeading::NORTH,
      $direction->y > 0 => MovementHeading::SOUTH,
      $direction->x < 0 => MovementHeading::WEST,
      $direction->x > 0 => MovementHeading::EAST,
      default => $this->heading,
    };

    $this->sprite = match ($this->heading) {
      MovementHeading::NORTH => $this->upSprite,
      MovementHeading::EAST => $this->rightSprite,
      MovementHeading::SOUTH => $this->downSprite,
      MovementHeading::WEST => $this->leftSprite,
      default => $this->sprite,
    };
  }

  /**
   * Updates the player position.
   *
   * @param Vector2 $direction The direction.
   * @param Camera $camera The camera.
   * @param string[]|null $previousSprite The sprite to erase from the previous position.
   * @return void
   */
  protected function updatePlayerPosition(Vector2 $direction, Camera $camera, ?array $previousSprite = null): void
  {
    $mapManager = $this->getGameScene()->mapManager;
    $this->erasePlayer($camera, $previousSprite ?? $this->sprite);
    $this->position->add($direction);
    if ( $mapManager->scrollMap($this, $direction) ) {
      $mapManager->render();
    }
    $this->render();
    $this->renderLocationHUDWindow();
  }

  /**
   * Renders the location HUD window.
   *
   * @return void
   */
  protected function renderLocationHUDWindow(): void
  {
    if ($this->canShowLocationHUDWindow) {
      $locationHUDWindow = $this->getLocationHUDWindow();
      $locationHUDWindow->updateDetails($this->position, $this->heading);
    }
  }

  /**
   * Sets the active player sprite and synchronizes the heading when possible.
   *
   * @param string[] $sprite The sprite rows to display.
   * @param MovementHeading|null $heading The heading to force, if already known.
   * @return void
   */
  public function setFacingSprite(array $sprite, ?MovementHeading $heading = null): void
  {
    $this->sprite = $sprite;
    $this->heading = $heading ?? $this->resolveHeadingFromSprite($sprite);
  }

  /**
   * Applies the configured directional sprite set for movement updates.
   *
   * @param array<string, string[]> $directionalSprites The directional sprite map.
   * @return void
   */
  protected function configureDirectionalSprites(array $directionalSprites): void
  {
    if (isset($directionalSprites['north'])) {
      $this->upSprite = PlayerSpriteSet::normalizeSprite($directionalSprites['north']);
    }

    if (isset($directionalSprites['east'])) {
      $this->rightSprite = PlayerSpriteSet::normalizeSprite($directionalSprites['east']);
    }

    if (isset($directionalSprites['south'])) {
      $this->downSprite = PlayerSpriteSet::normalizeSprite($directionalSprites['south']);
    }

    if (isset($directionalSprites['west'])) {
      $this->leftSprite = PlayerSpriteSet::normalizeSprite($directionalSprites['west']);
    }
  }

  /**
   * Returns the active directional sprite set.
   *
   * @return array<string, string[]> The configured directional sprite map.
   */
  public function getDirectionalSprites(): array
  {
    return [
      'north' => $this->upSprite,
      'east' => $this->rightSprite,
      'south' => $this->downSprite,
      'west' => $this->leftSprite,
    ];
  }

  /**
   * Resolves a heading from the current directional sprite set.
   *
   * @param string[] $sprite The sprite rows to inspect.
   * @return MovementHeading The heading that matches the sprite.
   */
  protected function resolveHeadingFromSprite(array $sprite): MovementHeading
  {
    return match (true) {
      $sprite === $this->upSprite => MovementHeading::NORTH,
      $sprite === $this->rightSprite => MovementHeading::EAST,
      $sprite === $this->downSprite => MovementHeading::SOUTH,
      $sprite === $this->leftSprite => MovementHeading::WEST,
      default => MovementHeading::NONE,
    };
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
    $this->scene->camera->renderAtScreenPosition($this->sprite, $this->screenPosition);

    if ($this->canAct) {
      $this->scene->camera->draw(
        $this->actionSprite,
        $this->screenPosition->x + $this->getActionSpriteHorizontalOffset(),
        clamp($this->screenPosition->y - 1, 1, get_screen_height())
      );
    }
  }

  public function renderPlayer(?Vector2 $offset = null): void
  {
    $worldPosition = new Vector2(
      $this->position->x - ($offset?->x ?? 0),
      $this->position->y - ($offset?->y ?? 0)
    );
    $screenPosition = $this->getRenderScreenPosition($worldPosition);

    for ($row = $this->shape->getY(); $row < $this->shape->getY() + $this->shape->getHeight(); $row++) {
      $output = TerminalText::sliceSymbols($this->sprite[$row], $this->shape->getX(), $this->shape->getWidth());
      $this->scene->camera->renderAtScreenPosition($output, new Vector2($screenPosition->x, $screenPosition->y + $row));
    }

    if ($this->canAct) {
      $this->scene->camera->draw($this->actionSprite, $screenPosition->x + $this->getActionSpriteHorizontalOffset(), clamp($screenPosition->y - 1, 1, get_screen_height()));
    }
  }

  /**
   * @inheritDoc
   */
  public function erase(): void
  {
    $this->eraseSpriteFootprint($this->position, $this->sprite);

    if ($this->canAct) {
      $this->eraseActionFootprint($this->position, $this->sprite);
    }
  }

  /**
   * Erases the player.
   *
   * @param Camera $camera The camera.
   * @param string[]|null $sprite The sprite footprint to erase.
   * @return void
   */
  public function erasePlayer(Camera $camera, ?array $sprite = null): void
  {
    $sprite ??= $this->sprite;
    $this->eraseSpriteFootprint($this->position, $sprite);

    if ($this->canAct) {
      $this->eraseActionFootprint($this->position, $sprite);
    }
  }

  /**
   * Returns the screen position used for rendering the current sprite.
   *
   * Wide glyphs such as emoji occupy multiple terminal cells, so we apply a
   * small horizontal offset to keep the logical collision tile and the visual
   * sprite feeling aligned.
   *
   * @param Vector2 $worldPosition The world position being rendered.
   * @return Vector2 The adjusted screen-space position.
   */
  protected function getRenderScreenPosition(Vector2 $worldPosition): Vector2
  {
    $screenPosition = $this->scene->camera->getScreenSpacePosition($worldPosition);

    return new Vector2(
      $screenPosition->x - $this->getHorizontalRenderOffset($this->sprite),
      $screenPosition->y
    );
  }

  /**
   * Returns the horizontal render offset needed for the given sprite.
   *
   * @param string[] $sprite The sprite rows to inspect.
   * @return int The horizontal render offset in terminal cells.
   */
  protected function getHorizontalRenderOffset(array $sprite): int
  {
    $extraWidth = max(0, $this->getSpriteDisplayWidth($sprite) - $this->shape->getWidth());

    return intdiv($extraWidth + 1, 2);
  }

  /**
   * Returns the horizontal offset used to center the action prompt above the sprite.
   *
   * @return int The horizontal action-sprite offset.
   */
  protected function getActionSpriteHorizontalOffset(): int
  {
    return max(0, intdiv($this->getSpriteDisplayWidth($this->sprite) - TerminalText::displayWidth($this->actionSprite), 2));
  }

  /**
   * Returns the display width of the widest sprite row.
   *
   * @param string[] $sprite The sprite rows to inspect.
   * @return int The widest row width.
   */
  protected function getSpriteDisplayWidth(array $sprite): int
  {
    $width = 0;

    foreach ($sprite as $row) {
      $width = max($width, TerminalText::displayWidth($row));
    }

    return max(1, $width);
  }

  /**
   * Re-renders the map tiles covered by the sprite footprint.
   *
   * @param Vector2 $worldPosition The world position being erased.
   * @param string[] $sprite The sprite rows to inspect.
   * @return void
   */
  protected function eraseSpriteFootprint(Vector2 $worldPosition, array $sprite): void
  {
    $startX = intval($worldPosition->x) - $this->getHorizontalRenderOffset($sprite);
    $width = max($this->shape->getWidth(), $this->getSpriteDisplayWidth($sprite));

    for ($row = 0; $row < max($this->shape->getHeight(), count($sprite)); $row++) {
      for ($column = 0; $column < $width; $column++) {
        $tileX = $startX + $column;
        $tileY = intval($worldPosition->y) + $row;

        if ($tileX < 0 || $tileY < 0) {
          continue;
        }

        $this->scene->renderBackgroundTile($tileX, $tileY);
      }
    }
  }

  /**
   * Re-renders the map tiles covered by the action prompt above the sprite.
   *
   * @param Vector2 $worldPosition The world position being erased.
   * @param string[] $sprite The sprite rows to inspect.
   * @return void
   */
  protected function eraseActionFootprint(Vector2 $worldPosition, array $sprite): void
  {
    $tileY = intval($worldPosition->y) - 1;

    if ($tileY < 0) {
      return;
    }

    $startX = intval($worldPosition->x) - $this->getHorizontalRenderOffset($sprite);
    $width = max(1, $this->getSpriteDisplayWidth($sprite));

    for ($column = 0; $column < $width; $column++) {
      $tileX = $startX + $column;

      if ($tileX < 0) {
        continue;
      }

      $this->scene->renderBackgroundTile($tileX, $tileY);
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
