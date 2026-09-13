<?php

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;

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
use Ichiloto\Engine\Events\Interfaces\AutomaticEventTriggerInterface;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Exceptions\OutOfBounds;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Sprites\DirectionalGraphicalSpriteSet;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProviderInterface;
use Ichiloto\Engine\Rendering\Sprites\SpriteWalkAnimation;
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
class Player extends GameObject implements GraphicalSpriteProviderInterface
{
  private ?SpriteWalkAnimation $walkAnimation = null;
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
   * @var array<int, true> Blocked triggers already announced, keyed by object
   * id, so a locked door explains itself once per approach rather than on
   * every step inside its area.
   */
  protected array $announcedBlockedEvents = [];
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
   * @param DirectionalGraphicalSpriteSet|null $graphicalSprites Optional graphical art, independent of terminal sprites.
   */
  public function __construct(
    SceneInterface $scene,
    string $name,
    Vector2 $position,
    Rect $shape,
    array $sprite,
    MovementHeading $heading = MovementHeading::NONE,
    array $directionalSprites = [],
    private readonly ?DirectionalGraphicalSpriteSet $graphicalSprites = null,
  )
  {
    parent::__construct(
      $scene,
      $name,
      $position,
      $shape,
      $sprite
    );

    if ($graphicalSprites !== null) {
      $this->walkAnimation = new SpriteWalkAnimation();
    }
    $this->configureDirectionalSprites($directionalSprites);
    $this->setFacingSprite($sprite, $heading === MovementHeading::NONE ? null : $heading);
    $this->canShowLocationHUDWindow = config(ProjectConfig::class, 'ui.hud.location', false);
    $this->events = new ItemList(EventTrigger::class);
  }

  #[Override]
  public function getGraphicalSpriteId(): string
  {
    return 'player';
  }

  #[Override]
  public function getGraphicalSpriteDefinition(): ?GraphicalSpriteDefinition
  {
    $definition = $this->graphicalSprites?->getForHeading($this->heading);
    return $definition === null ? null : ($this->walkAnimation?->present($definition) ?? $definition);
  }

  public function advanceGraphicalAnimation(float $seconds): void
  {
    $this->walkAnimation?->advance($seconds);
  }

  public function stopGraphicalAnimation(): void
  {
    $this->walkAnimation?->stop();
  }

  #[Override]
  public function getGraphicalSpriteWorldPosition(): Vector2
  {
    return clone $this->position;
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
    $this->tryMove($direction, $camera);
  }

  /**
   * Attempts a real field movement and reports whether it succeeded.
   *
   * Player input keeps using {@see move()}; story routes use this result so
   * a collision becomes a controlled script failure instead of an endless
   * wait or a silently skipped step.
   *
   * @param Vector2 $direction The cardinal movement vector.
   * @param Camera $camera The field camera.
   * @return bool True when the player moved.
   * @throws NotFoundException If the scene is not set.
   * @throws OutOfBounds If the destination is out of bounds.
   */
  public function tryMove(Vector2 $direction, Camera $camera): bool
  {
    // Clone: $this->position is mutated by the move below, so holding a
    // reference would make the movement event report an origin equal to its
    // destination.
    $origin = clone $this->position;
    $destination = Vector2::sum($origin, $direction);
    $collisionType = null;
    $previousSprite = $this->sprite;
    $this->updatePlayerSprite($direction);

    if (! $this->getGameScene()->mapManager->canMoveTo(intval($destination->x), intval($destination->y), $collisionType) ) {
      $this->stopGraphicalAnimation();
      $this->render();
      return false;
    }

    $event = new MovementEvent(MovementEventType::PLAYER_MOVE, $origin, $destination);

    // A conditioned event with a blocked message is an authored field gate,
    // not merely an advisory notification. Reject entry before mutating the
    // player position, advancing encounters, or notifying movement observers.
    if ($this->isMovementBlockedByUnavailableEvent($event)) {
      $this->stopGraphicalAnimation();
      $this->render();
      return false;
    }

    $this->handleCollision($collisionType);
    $fieldWasRecomposed = $this->updatePlayerPosition($direction, $camera, $previousSprite);
    if ($this->walkAnimation !== null
      && ($origin->x !== $this->position->x || $origin->y !== $this->position->y)) {
      $this->walkAnimation->step($this->graphicalSprites->getForHeading($this->heading));
    }
    $this->handleTriggers($event);
    $this->getGameScene()->encounterManager?->registerStep($collisionType);

    // An ordinary step can erase an NPC that occupied the player's previous
    // footprint, so refresh NPCs after the player moves. A scrolling step has
    // already rebuilt every field layer in canonical order and must not draw
    // NPCs over the player a second time.
    if (! $fieldWasRecomposed) {
      $this->getGameScene()->npcManager?->render();
    }

    if ($this->getGameScene()->mapManager->isAtSavePoint) {
      alert("Access the Menu to save your progress.", 'Save Point');
    }
    $this->notify($this->getGameScene(), $event);

    return true;
  }

  /**
   * Faces a cardinal direction without changing tiles.
   *
   * @param Vector2 $direction The direction to face.
   * @param Camera $camera The field camera.
   * @return void
   */
  public function face(Vector2 $direction, Camera $camera): void
  {
    $this->stopGraphicalAnimation();
    $previousSprite = $this->sprite;
    $this->updatePlayerSprite($direction);
    $this->erasePlayer($camera, $previousSprite);
    $this->render();
    $this->renderLocationHUDWindow();
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
   * Determines whether an unavailable event rejects the attempted entry.
   *
   * A non-empty whenBlocked message makes the event area fail closed while
   * its conditions do not hold. Movement from inside the area remains
   * permitted so a loaded save or a condition change cannot trap the player.
   *
   * @param MovementEvent $movementEvent The attempted movement.
   * @return bool True when the movement must not update field state.
   */
  protected function isMovementBlockedByUnavailableEvent(MovementEvent $movementEvent): bool
  {
    $blockingEvent = null;

    /** @var EventTrigger $event */
    foreach ($this->events as $event) {
      $eventId = spl_object_id($event);
      $destinationIsInside = $event->area->contains($movementEvent->destination);

      if (! $destinationIsInside || $event->isComplete || $event->isAvailable() || $event->whenBlocked === null) {
        unset($this->announcedBlockedEvents[$eventId]);
        continue;
      }

      // Never trap a player whose save already places them inside a gate.
      // The transition from outside to inside is the authoritative boundary.
      if ($event->area->contains($movementEvent->origin)) {
        continue;
      }

      $blockingEvent ??= $event;
    }

    if ($blockingEvent === null) {
      return false;
    }

    $eventId = spl_object_id($blockingEvent);

    if (! isset($this->announcedBlockedEvents[$eventId])) {
      $this->announcedBlockedEvents[$eventId] = true;
      $this->announceBlockedEvent($blockingEvent->whenBlocked);
    }

    return true;
  }

  /**
   * Presents the authored explanation for a blocked field event.
   *
   * Kept behind a method so movement policy stays independently testable
   * from the terminal modal implementation.
   *
   * @param string $message The authored blocked-event message.
   * @return void
   */
  protected function announceBlockedEvent(string $message): void
  {
    $this->stopGraphicalAnimation();
    alert($message);
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
        // A one-shot action trigger can complete while the player is still
        // standing inside it. It remains in activeEvents until the next
        // movement, so its exit hook must still run to clear transient field
        // state such as availableAction. Skipping completed triggers before
        // this cleanup leaves a stale action prompt that can block NPC and
        // object interaction on every later map.
        if ($this->eventManager->activeEvents->contains($event)) {
          $event->exit($eventTriggerContext);
          $this->eventManager->activeEvents->remove($event);
        }

        continue;
      }

      if (! $event->isAvailable()) {
        // Conditions no longer hold — treat an active trigger as exited.
        if ($this->eventManager->activeEvents->contains($event)) {
          $event->exit($eventTriggerContext);
          $this->eventManager->activeEvents->remove($event);
        }

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
   * Starts the first available automatic script under the current position.
   *
   * Initial field entry does not produce a movement event, but an automatic
   * map script must still run when a new game or loaded save spawns inside its area.
   * Action and transfer triggers remain movement-driven.
   *
   * @return void
   */
  public function evaluateAutomaticTriggersAtCurrentPosition(): void
  {
    $position = clone $this->position;
    $movementEvent = new MovementEvent(MovementEventType::PLAYER_MOVE, $position, clone $position);
    $context = new EventTriggerContext(
      $movementEvent,
      $this->position,
      $this,
      $this->getGameScene(),
      $this->getGameScene()->mapManager,
    );

    foreach ($this->events as $event) {
      if (
        ! $event instanceof AutomaticEventTriggerInterface
        || ! $event->runsAutomatically()
        || $event->isComplete
        || ! $event->isAvailable()
        || ! $event->area->contains($this->position)
        || $this->eventManager->activeEvents->contains($event)
      ) {
        continue;
      }

      $this->eventManager->activeEvents->add($event);
      $event->enter($context);

      if ($event->isComplete) {
        $this->eventManager->activeEvents->remove($event);
      }

      break;
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

    $this->removeEventTriggers();
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
   * @return bool True when camera movement invoked the complete field compositor.
   */
  protected function updatePlayerPosition(Vector2 $direction, Camera $camera, ?array $previousSprite = null): bool
  {
    $scene = $this->getGameScene();
    $mapManager = $scene->mapManager;
    $this->erasePlayer($camera, $previousSprite ?? $this->sprite);
    $this->position->add($direction);
    $didScroll = $mapManager->scrollMap($this, $direction);
    $this->renderLocationHUDWindow();

    if ($didScroll && $scene->recomposeFieldAfterCameraScroll()) {
      return true;
    }

    if ($didScroll) {
      // Lightweight scenes and test harnesses may not own a FieldState. Keep
      // their fallback complete enough to preserve authored event cues.
      $mapManager->render();
    }

    // Restoring the player's old tile can erase a cue underneath it even
    // without scrolling. Repaint cues before foreground actors so their
    // established layer order remains map -> cues -> actors.
    $this->renderEventCues();
    $this->render();

    return false;
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
    $this->stopGraphicalAnimation();
    // Spawn data may name a heading rather than spell out the art, so a map
    // never has to repeat the project's sprites.
    if (($named = PlayerSpriteSet::headingFromName($sprite)) !== null) {
      $heading ??= $named;
      $sprite = $this->getSpriteForHeading($named);
    }

    $sprite = PlayerSpriteSet::normalizeSprite($sprite);
    $resolvedHeading = $heading ?? $this->resolveHeadingFromSprite($sprite);

    // A sprite that belongs to no direction (a placeholder glyph in the
    // project's spawn data, say) would otherwise be drawn verbatim and leave
    // the player facing nowhere. Fall back to the configured art for the
    // heading so every direction always shows its own sprite.
    if ($resolvedHeading === MovementHeading::NONE) {
      $resolvedHeading = MovementHeading::SOUTH;
      $sprite = $this->getSpriteForHeading($resolvedHeading);
    } elseif ($heading !== null && $sprite !== $this->getSpriteForHeading($resolvedHeading)) {
      // An explicit heading wins over a mismatched sprite.
      $sprite = $this->getSpriteForHeading($resolvedHeading);
    }

    $this->sprite = $sprite;
    $this->heading = $resolvedHeading;
  }

  /**
   * Returns the configured sprite for a heading.
   *
   * @param MovementHeading $heading The heading.
   * @return string[] The sprite rows.
   */
  public function getSpriteForHeading(MovementHeading $heading): array
  {
    return match ($heading) {
      MovementHeading::NORTH => $this->upSprite,
      MovementHeading::EAST => $this->rightSprite,
      MovementHeading::WEST => $this->leftSprite,
      default => $this->downSprite,
    };
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
    // EventManager is shared by the running game, so clearing only the
    // player's map-local list leaves entered triggers alive across a map
    // transfer. Besides leaking the old objects, an action trigger keeps its
    // RunScriptAction attached to the player and renders a phantom "!" on the
    // destination map. Retire the active membership and prompt together with
    // the map-owned definitions.
    foreach ($this->events as $event) {
      if ($this->eventManager->activeEvents->contains($event)) {
        $this->eventManager->activeEvents->remove($event);
      }
    }

    $this->events->clear();
    $this->availableAction = null;
    $this->announcedBlockedEvents = [];
  }

  /** Returns the top-left position of a stable current-map event marker. */
  public function findEventMarkerPosition(string $marker): ?Vector2
  {
    $marker = trim($marker);

    foreach ($this->events as $event) {
      if ($event->marker === $marker) {
        return new Vector2($event->area->getX(), $event->area->getY());
      }
    }

    return null;
  }

  /**
   * Renders authored cues for available, incomplete map events.
   *
   * Event-layer marker letters remain editor-only identities. A cue exists
   * only when an author deliberately opts a trigger into player guidance.
   */
  public function renderEventCues(): void
  {
    /** @var EventTrigger $event */
    foreach ($this->events as $event) {
      if (! $event->shouldRenderCue()) {
        continue;
      }

      $this->scene->camera->renderOnScreen(
        [$event->cue->styledSymbol()],
        $event->cue->positionFor($event->area),
      );
    }
  }

  /**
   * Retires active triggers whose completion or conditions changed in place.
   *
   * A script can complete without player movement. Running only the exit half
   * here clears stale action prompts without entering newly available triggers
   * or unexpectedly chaining automatic scripts at the same coordinates.
   */
  public function reconcileActiveEventState(): void
  {
    $position = clone $this->position;
    $context = new EventTriggerContext(
      new MovementEvent(MovementEventType::PLAYER_MOVE, $position, clone $position),
      $this->position,
      $this,
      $this->getGameScene(),
      $this->getGameScene()->mapManager,
    );

    /** @var EventTrigger $event */
    foreach ($this->events as $event) {
      if (
        $this->eventManager->activeEvents->contains($event)
        && ($event->isComplete || ! $event->isAvailable())
      ) {
        $event->exit($context);
        $this->eventManager->activeEvents->remove($event);
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function render(): void
  {
    Console::withLayer($this->getGraphicalSpriteId(), function (): void {
      $this->scene->camera->renderAtScreenPosition($this->sprite, $this->screenPosition);
    });

    if ($this->canAct) {
      PresentationLayerPolicy::fieldPrompt(fn() => $this->scene->camera->draw(
        $this->actionSprite,
        $this->screenPosition->x + $this->getActionSpriteHorizontalOffset(),
        clamp($this->screenPosition->y - 1, 1, get_screen_height())
      ));
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
      PresentationLayerPolicy::fieldPrompt(fn() => $this->scene->camera->draw($this->actionSprite, $screenPosition->x + $this->getActionSpriteHorizontalOffset(), clamp($screenPosition->y - 1, 1, get_screen_height())));
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
    // A sprite is anchored to its own tile: its first column is the tile's
    // column. Glyphs wider than one cell (emoji are two) overhang to the
    // right. Shifting them left to "centre" them instead made a character
    // standing beside a wall appear to be standing on it.
    return $this->scene->camera->getScreenSpacePosition($worldPosition);
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
    $startX = intval($worldPosition->x);
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

    $startX = intval($worldPosition->x);
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
    $this->stopGraphicalAnimation();
    if ($this->availableAction === null && $this->talkToFacingNpc()) {
      return;
    }

    $this->availableAction?->execute(new FieldActionContext(
      $this,
      $this->getGameScene(),
      $this->position
    ));
  }

  /**
   * Talks to the NPC on the tile the player faces, when one is there.
   *
   * @return bool True when a conversation happened.
   */
  protected function talkToFacingNpc(): bool
  {
    [$dx, $dy] = match ($this->heading) {
      MovementHeading::NORTH => [0, -1],
      MovementHeading::SOUTH => [0, 1],
      MovementHeading::EAST => [1, 0],
      MovementHeading::WEST => [-1, 0],
      default => [0, 0],
    };

    if ($dx === 0 && $dy === 0) {
      return false;
    }

    $npc = $this->getGameScene()->npcManager?->npcAt(
      intval($this->position->x) + $dx,
      intval($this->position->y) + $dy
    );

    if ($npc === null) {
      return false;
    }

    $npc->talk($this->getGameScene());

    return true;
  }
}
