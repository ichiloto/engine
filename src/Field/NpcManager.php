<?php

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Sprites\CharacterStep;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProviderInterface;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;
use RuntimeException;

/**
 * Manages the current map's field NPCs: visibility, wandering, rendering,
 * and collision.
 *
 * @package Ichiloto\Engine\Field
 */
class NpcManager
{
  protected const float MIN_WANDER_INTERVAL_SECONDS = 1.5;
  protected const float MAX_WANDER_INTERVAL_SECONDS = 4.0;

  /**
   * @var Npc[] The current map's NPCs.
   */
  protected(set) array $npcs = [];

  /**
   * NpcManager constructor.
   *
   * @param GameScene $gameScene The owning game scene.
   */
  public function __construct(protected GameScene $gameScene)
  {
  }

  /**
   * Loads a map's NPC block.
   *
   * @param array<int, array<string, mixed>> $entries The map's `npcs` entries.
   * @return void
   */
  public function configure(array $entries): void
  {
    $objects = array_map(static fn(WorldObject $object): WorldObjectDefinition => $object->definition,
      iterator_to_array($this->gameScene->mapManager?->getWorldObjects() ?? new \EmptyIterator()));
    $this->applyPreparedNpcs($this->prepareNpcs($entries, $this->gameScene->currentMapId, $objects));
  }

  /** Builds a destination's NPC list without changing the current map. */
  public function prepareNpcs(array $entries, string $mapId, array $worldObjects = []): array
  {
    $npcs = [];
    $ids = [];

    foreach (array_values($entries) as $index => $entry) {
      if (is_array($entry) && array_key_exists('placements', $entry) && !isset($this->gameScene->gameState)) {
        throw new RuntimeException('Named NPC placements require the current world state.');
      }
      $placement = NpcPlacement::fromArray($entry, isset($this->gameScene->gameState) ? $this->gameScene->gameState : null, $mapId);

      if ($placement === null) {
        continue;
      }

      $name = $placement->name;
      $wanderArea = $placement->wanderArea;
      $id = $placement->id ?? '';

      if ($id !== '' && isset($ids[$id])) {
        throw new RuntimeException(sprintf(
          'Duplicate NPC id "%s" on map "%s".',
          $id,
          $mapId,
        ));
      }
      if ($id !== '') {
        $ids[$id] = true;
      }

      $graphicalSprites = $this->loadGraphicalSprites($entry, $mapId, $id !== '' ? $id : "entry:$index ($name)");
      try {
        $pages = NpcPages::fromEntry($entry);
      } catch (InvalidArgumentException $error) {
        throw new RuntimeException(sprintf('NPC "%s" on map "%s": %s', $id !== '' ? $id : $name, $mapId, $error->getMessage()), previous: $error);
      }
      $npc = new Npc(
        name: $name,
        sprite: strval($entry['sprite'] ?? '@'),
        position: new Vector2($placement->x, $placement->y),
        wanders: $placement->wanders,
        wanderArea: $wanderArea,
        pages: $pages,
        conditions: $placement->conditions,
        sets: array_values(array_filter((array) ($entry['sets'] ?? []), is_array(...))),
        id: $id !== '' ? $id : null,
        directionalSprites: is_array($entry['sprites'] ?? null) ? $entry['sprites'] : [],
        graphicalSprites: $graphicalSprites,
        graphicalSpriteId: 'npc:map:' . rawurlencode($mapId) . ':'
          . ($id !== '' ? 'id:' . rawurlencode($id) : 'entry:' . $index),
        assetRoot: $graphicalSprites === null ? null
          : ($this->gameScene->getGame()->getRendererRuntime()?->getAssetRoot() ?? getcwd() . '/assets'),
        directionFix: ($entry['directionFix'] ?? false) === true,
        facing: $placement->placements === null ? self::readFacing($entry)
          : $placement->placements->placements[$placement->placements->readSelection($this->gameScene->gameState, $mapId, $id)]['facing'],
      );
      if (isset($this->gameScene->gameState)) {
        $npc->bind($mapId, $this->gameScene->gameState, $this->gameScene->party);
      }
      if ($placement->placements !== null) {
        $npc->bindPlacementState(new NpcPlacementState($placement->placements, $npc, $this->gameScene->gameState, $mapId, $id));
      }
      if (array_key_exists('bed', $entry)) {
        if (!isset($this->gameScene->gameState)) { throw new RuntimeException('NPC beds require the current world state.'); }
        $bed = NpcBedDefinition::fromArray($entry['bed']);
        [$object, $attachment] = $bed->resolveObject($worldObjects);
        $npc->bindBed(new NpcBed($bed, $npc, $object, $attachment, new Vector2($placement->x, $placement->y),
          $this->gameScene->gameState, $mapId,
          $this->gameScene->getGame()->getRendererRuntime()?->getAssetRoot() ?? getcwd() . '/assets', $this->gameScene));
      }
      $npcs[] = $npc;
    }

    $this->validatePreparedPlacements($npcs);
    return $npcs;
  }

  /** Current saved choices may not install overlapping real NPCs. */
  private function validatePreparedPlacements(array $npcs): void
  {
    if (!array_any($npcs, static fn(Npc $npc): bool => $npc->placementState !== null)) { return; }
    $claims = [];
    foreach ($npcs as $npc) {
      if (!$this->conditionsHold($npc->conditions)) { continue; }
      $key = (int)$npc->position->x . ':' . (int)$npc->position->y;
      foreach ($claims[$key] ?? [] as $other) {
        if ($npc->placementState !== null || $other->placementState !== null) {
          throw new InvalidArgumentException('A saved NPC placement overlaps another present NPC; correct current source or migrate its selection.');
        }
      }
      $claims[$key][] = $npc;
    }
  }

  /**
   * Reads the heading an NPC entry stands with: `facing`, one of the cardinal
   * names in any case. Absent or unknown reads as south, the field's default;
   * the Editor reports an unknown value.
   */
  public static function readFacing(array $entry): MovementHeading
  {
    $facing = is_string($entry['facing'] ?? null) ? MovementHeading::tryFrom(ucfirst(strtolower(trim($entry['facing'])))) : null;

    return $facing === null || $facing === MovementHeading::NONE ? MovementHeading::SOUTH : $facing;
  }

  /** @param Npc[] $npcs */
  public function applyPreparedNpcs(array $npcs): void
  {
    $this->stopGraphicalAnimation();
    foreach ($this->npcs as $npc) { if (!in_array($npc, $npcs, true)) { $npc->releaseFieldPresentation(); } }
    $this->npcs = $npcs;
  }

  /** Malformed optional art must not abort map loading or remove a gameplay subject. */
  private function loadGraphicalSprites(array $entry, string $mapId, string $identity): ?CharacterSheet
  {
    if (!array_key_exists('sprites2d', $entry)) {
      return null;
    }
    try {
      if (!is_array($entry['sprites2d'])) {
        throw new InvalidArgumentException('sprites2d must be a character sheet definition array.');
      }
      $sprites = CharacterSheet::fromArray($entry['sprites2d']);
      if ($sprites->layer < PresentationLayerPolicy::WORLD || $sprites->layer >= PresentationLayerPolicy::FIELD_GUIDANCE) {
        throw new InvalidArgumentException('Automatic Game world sprites require layers 0..999; guidance and UI layers are reserved.');
      }
      return $sprites;
    } catch (InvalidArgumentException $error) {
      Debug::warn(sprintf('NPC "%s" on map "%s" has invalid sprites2d; keeping terminal sprite: %s',
        $identity, $mapId, $error->getMessage()));
      return null;
    }
  }

  /** @return list<GraphicalSpriteProviderInterface> Same subjects as ordinary terminal rendering. */
  public function getGraphicalSpriteProviders(): array
  {
    return array_values(array_filter($this->npcs, $this->isPresentationVisible(...)));
  }

  public function findBedOccupant(string $objectId): ?Npc
  {
    $occupants = array_values(array_filter($this->npcs, fn(Npc $npc): bool => $npc->bed?->isOccupied
      && $npc->bed->definition->objectId === $objectId && $this->isFieldVisible($npc)));
    if (count($occupants) > 1) { throw new RuntimeException('More than one current NPC occupies the same bed.'); }
    return $occupants[0] ?? null;
  }

  /** Called alongside the Player/staged-actor tick, including during event routes. */
  public function advanceGraphicalAnimation(float $seconds): void
  {
    foreach ($this->npcs as $npc) {
      if ($this->isPresentationVisible($npc)) {
        $npc->advanceGraphicalAnimation($seconds);
      } else {
        $npc->stopGraphicalAnimation();
      }
    }
  }

  public function stopGraphicalAnimation(): void
  {
    foreach ($this->npcs as $npc) {
      $npc->stopGraphicalAnimation();
    }
  }

  /** Cinematics retain active subjects independently of ordinary field occupancy. */
  public function canStageNpc(Npc $npc): bool
  {
    return in_array($npc, $this->npcs, true) && $this->conditionsHold($npc->conditions);
  }

  /** Field occupancy is deferred, not relocated, while the player occupies the NPC's cell. */
  private function isFieldVisible(Npc $npc): bool
  {
    $player = $this->gameScene->player;

    return $this->conditionsHold($npc->conditions)
      && ($player === null
        || intval($player->position->x) !== intval($npc->position->x)
        || intval($player->position->y) !== intval($npc->position->y));
  }

  private function isPresentationVisible(Npc $npc): bool
  {
    return $this->isFieldVisible($npc) && !($this->gameScene->cinematicStage?->suppresses($npc) ?? false);
  }

  /**
   * Ticks NPC wandering.
   *
   * @return void
   */
  public function update(): void
  {
    $now = Time::getTime();

    foreach ($this->visibleNpcs() as $npc) {
      if (! $npc->wanders || $npc->bed?->isOccupied) {
        continue;
      }

      if ($npc->nextWanderTime <= 0.0) {
        $npc->nextWanderTime = $now + $this->nextInterval();
        continue;
      }

      if ($now < $npc->nextWanderTime) {
        continue;
      }

      $npc->nextWanderTime = $now + $this->nextInterval();
      $this->takeWanderStep($npc);
    }
  }

  /**
   * Renders every visible NPC.
   *
   * @return void
   */
  public function render(): void
  {
    foreach ($this->visibleNpcs() as $npc) {
      $this->renderNpc($npc);
    }
  }

  protected function renderNpc(Npc $npc): void
  {
    if ($this->isPresentationVisible($npc)) {
      Console::withLayer($npc->getGraphicalSpriteId(), function () use ($npc): void {
        $this->gameScene->camera->renderOnScreen([$npc->getFieldTerminalSprite()], $npc->position);
      });
    }
    $this->renderCue($npc);
  }

  /**
   * Draws an NPC's cue glyph in the cell above it while the cue shows,
   * unless graphical cue art draws it. The glyph follows the NPC as it moves.
   */
  private function renderCue(Npc $npc): void
  {
    $layer = 'npc-cue:' . $npc->getGraphicalSpriteId();
    Console::removeLayer($layer);
    if ($npc->cue === null || !$this->shouldRenderCue($npc)
      || ($this->gameScene->isGraphicalFieldPresented() && ($this->gameScene->fieldEffects?->canPresentNpcCue($npc) ?? false))) {
      return;
    }
    $glyph = $this->gameScene->fieldEffects?->cueStyle->styleGlyph($npc->cue->symbol, $npc->cue->color) ?? $npc->cue->styledSymbol();
    Console::withLayer($layer, fn() => $this->gameScene->camera->renderOnScreen([$glyph],
      new Vector2($npc->position->x, $npc->position->y - 1)), PresentationLayerPolicy::WORLD);
  }

  /** Whether an NPC's cue shows now: the NPC is on the field, guidance shows and the cue's own conditions hold. */
  public function shouldRenderCue(Npc $npc): bool
  {
    return $npc->cue !== null && $this->isPresentationVisible($npc) && $this->gameScene->canPresentFieldGuidance()
      && $npc->cue->isInRange($npc->position, $this->gameScene->player?->position)
      && $this->conditionsHold($npc->cue->conditions);
  }

  /** @return list<Npc> The NPCs whose cues show now. */
  public function listCuedNpcs(): array
  {
    return array_values(array_filter($this->npcs, $this->shouldRenderCue(...)));
  }

  /**
   * Returns the visible NPC standing at the given tile, if any.
   *
   * @param int $x The x-coordinate.
   * @param int $y The y-coordinate.
   * @return Npc|null The NPC at that tile.
   */
  public function npcAt(int $x, int $y): ?Npc
  {
    foreach ($this->visibleNpcs() as $npc) {
      if (intval($npc->position->x) === $x && intval($npc->position->y) === $y) {
        return $npc;
      }
    }

    return null;
  }

  /**
   * Finds a current-map NPC by its stable authored id.
   */
  public function findById(string $id): ?Npc
  {
    $id = trim($id);

    if ($id === '') {
      return null;
    }

    foreach ($this->npcs as $npc) {
      if ($npc->id === $id) {
        return $npc;
      }
    }

    return null;
  }

  /** Finds a stable-id NPC only while its conditions place it on the map. */
  public function findPresentById(string $id): ?Npc
  {
    $npc = $this->findById($id);

    return $npc !== null && $this->conditionsHold($npc->conditions) ? $npc : null;
  }

  /**
   * Moves a stable-id NPC one cardinal tile using current passability.
   */
  public function moveNpcById(string $id, Vector2 $direction): bool
  {
    $npc = $this->findById($id);

    if ($npc === null) {
      throw new RuntimeException(sprintf(
        'NPC id "%s" was not found on map "%s".',
        $id,
        $this->gameScene->currentMapId,
      ));
    }

    $destinationX = intval($npc->position->x + $direction->x);
    $destinationY = intval($npc->position->y + $direction->y);
    $player = $this->gameScene->player;

    if (
      $npc->bed?->isOccupied
      || ! $this->gameScene->mapManager->canMoveTo($destinationX, $destinationY)
      || ($player !== null
        && intval($player->position->x) === $destinationX
        && intval($player->position->y) === $destinationY)
    ) {
      $npc->stopGraphicalAnimation();
      $this->gameScene->cinematicStage?->subjectStopped($npc);
      return false;
    }

    $this->eraseNpc($npc);
    $npc->face($direction);
    $origin = clone $npc->position;
    $npc->position->x = $destinationX;
    $npc->position->y = $destinationY;
    $this->beginGraphicalStep($npc, new CharacterStep($origin, $npc->position,
      $this->gameScene->getStepSeconds($direction)));
    $this->renderNpc($npc);

    return true;
  }

  /**
   * Faces a stable-id NPC without moving it.
   */
  public function faceNpc(string $id, Vector2 $direction): bool
  {
    $npc = $this->findById($id);

    if ($npc === null) {
      throw new RuntimeException(sprintf(
        'NPC id "%s" was not found on map "%s".',
        $id,
        $this->gameScene->currentMapId,
      ));
    }

    $this->turnNpc($npc, $direction);

    return true;
  }

  /**
   * Turns an NPC toward a position, as a JRPG NPC turns to the player who
   * talks to it, remembering its heading so the end of the conversation can
   * turn it back (see restoreNpcHeadingAfterTalk()). A direction-fixed NPC,
   * one already in a conversation, or one a cinematic has staged keeps its
   * heading.
   *
   * @param Npc $npc The NPC to turn.
   * @param Vector2 $position The position to face, normally the player's.
   * @return bool True when the NPC turned.
   */
  public function turnNpcToward(Npc $npc, Vector2 $position): bool
  {
    if (
      $npc->directionFix
      || $npc->conversationIsActive
      || ($this->gameScene->cinematicStage?->suppresses($npc) ?? false)
    ) {
      return false;
    }

    $dx = intval($position->x) - intval($npc->position->x);
    $dy = intval($position->y) - intval($npc->position->y);

    if ($dx === 0 && $dy === 0) {
      return false;
    }

    $before = $npc->heading;
    // The dominant axis picks the cardinal heading; an adjacent talker is always on one axis.
    $this->turnNpc($npc, abs($dx) >= abs($dy) ? new Vector2($dx <=> 0, 0) : new Vector2(0, $dy <=> 0));

    if ($npc->heading !== $before) {
      $npc->rememberHeadingBeforeTalk($before);
    }

    return true;
  }

  /**
   * Turns an NPC back to the heading it had before the player talked to it,
   * as RPG Maker does when the event ends. Nothing happens when the talk
   * did not turn it, when the conversation itself turned, moved or staged
   * it (the NPC forgets the heading on any later face() or restored
   * transform), when a cinematic still stages it, or when it has left the
   * current map. A wanderer keeps its wander schedule.
   *
   * @param Npc $npc The NPC whose conversation ended.
   * @return bool True when the NPC turned back.
   */
  public function restoreNpcHeadingAfterTalk(Npc $npc): bool
  {
    $heading = $npc->takeHeadingBeforeTalk();

    if (
      $heading === null
      || ! in_array($npc, $this->npcs, true)
      || ($this->gameScene->cinematicStage?->suppresses($npc) ?? false)
    ) {
      return false;
    }

    $this->turnNpc($npc, $heading->getDirection());

    return true;
  }

  /** Faces an NPC in place, redrawing its terminal glyph and settling its graphical frame. */
  private function turnNpc(Npc $npc, Vector2 $direction): void
  {
    $this->eraseNpc($npc);
    $npc->face($direction);
    $npc->stopGraphicalAnimation();
    $this->gameScene->cinematicStage?->subjectStopped($npc);
    $this->renderNpc($npc);
  }

  /**
   * Returns NPCs whose conditions hold and whose cell is not occupied by the player.
   *
   * @return Npc[] The visible NPCs.
   */
  public function visibleNpcs(): array
  {
    return array_values(array_filter(
      $this->npcs,
      $this->isFieldVisible(...)
    ));
  }

  /**
   * Attempts one random wander step.
   *
   * @param Npc $npc The wandering NPC.
   * @return void
   */
  protected function takeWanderStep(Npc $npc): void
  {
    $directions = [[0, -1], [0, 1], [-1, 0], [1, 0]];
    [$dx, $dy] = $directions[array_rand($directions)];
    $destinationX = intval($npc->position->x) + $dx;
    $destinationY = intval($npc->position->y) + $dy;
    $player = $this->gameScene->player;

    if (
      ! $npc->allowsWanderTo($destinationX, $destinationY)
      || ! $this->gameScene->mapManager->canMoveTo($destinationX, $destinationY)
      || $this->npcAt($destinationX, $destinationY) !== null
      || ($player !== null
        && intval($player->position->x) === $destinationX
        && intval($player->position->y) === $destinationY)
    ) {
      return;
    }

    $this->eraseNpc($npc);
    $npc->face(new Vector2($dx, $dy));
    $origin = clone $npc->position;
    $npc->position->x = $destinationX;
    $npc->position->y = $destinationY;
    // A wander pause is not a pace: the step itself walks at field speed.
    $this->beginGraphicalStep($npc, new CharacterStep($origin, $npc->position,
      $this->gameScene->getStepSeconds(new Vector2($dx, $dy))));
    $this->renderNpc($npc);
  }

  private function beginGraphicalStep(Npc $npc, CharacterStep $step): void
  {
    if ($this->isPresentationVisible($npc)) {
      $npc->beginGraphicalStep($step);
    } else {
      $npc->stopGraphicalAnimation();
    }
    $this->gameScene->cinematicStage?->subjectMoved($npc, $step);
  }

  /**
   * Restores the map tiles an NPC's sprite covered.
   *
   * Sprites are anchored to their tile and overhang to the right, so a
   * two-column emoji covers two cells. Clearing only the anchor cell leaves
   * the other half of the glyph on the map.
   *
   * @param Npc $npc The NPC to erase.
   * @return void
   */
  protected function eraseNpc(Npc $npc): void
  {
    if (! $this->isPresentationVisible($npc)) {
      return;
    }
    $columns = max(1, TerminalText::displayWidth($npc->sprite));

    for ($column = 0; $column < $columns; $column++) {
      $this->gameScene->renderBackgroundTile(
        intval($npc->position->x) + $column,
        intval($npc->position->y)
      );
    }
  }

  /**
   * Evaluates an NPC's visibility conditions against the world state.
   *
   * @param array<int, array<string, mixed>> $conditions The condition entries.
   * @return bool True when every condition holds.
   */
  protected function conditionsHold(array $conditions): bool
  {
    return WorldConditionEvaluator::allHold(
      $conditions,
      $this->gameScene->gameState,
      $this->gameScene->party
    );
  }

  /**
   * Returns a randomized wander interval.
   *
   * @return float Seconds until the next step.
   */
  protected function nextInterval(): float
  {
    return rand(
      intval(self::MIN_WANDER_INTERVAL_SECONDS * 10),
      intval(self::MAX_WANDER_INTERVAL_SECONDS * 10)
    ) / 10;
  }
}
