<?php

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Core\Vector2;
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
    $this->applyPreparedNpcs($this->prepareNpcs($entries, $this->gameScene->currentMapId));
  }

  /** Builds a destination's NPC list without changing the current map. */
  public function prepareNpcs(array $entries, string $mapId): array
  {
    $npcs = [];
    $ids = [];

    foreach (array_values($entries) as $index => $entry) {
      if (! is_array($entry)) {
        continue;
      }

      $name = trim(strval($entry['name'] ?? ''));

      if ($name === '' || ! isset($entry['x'], $entry['y'])) {
        continue;
      }

      $wanderArea = null;

      if (is_array($entry['wanderArea'] ?? null)) {
        $wanderArea = [
          'x' => intval($entry['wanderArea']['x'] ?? 0),
          'y' => intval($entry['wanderArea']['y'] ?? 0),
          'width' => max(1, intval($entry['wanderArea']['width'] ?? 1)),
          'height' => max(1, intval($entry['wanderArea']['height'] ?? 1)),
        ];
      }

      $id = trim(strval($entry['id'] ?? ''));

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
      $npcs[] = new Npc(
        name: $name,
        sprite: strval($entry['sprite'] ?? '@'),
        position: new Vector2(intval($entry['x']), intval($entry['y'])),
        wanders: strval($entry['movement'] ?? 'fixed') === 'wander',
        wanderArea: $wanderArea,
        dialogue: array_values(array_filter((array) ($entry['dialogue'] ?? []), is_array(...))),
        script: array_values(array_filter((array) ($entry['script'] ?? []), is_array(...))),
        conditions: array_values(array_filter((array) ($entry['conditions'] ?? []), is_array(...))),
        sets: array_values(array_filter((array) ($entry['sets'] ?? []), is_array(...))),
        id: $id !== '' ? $id : null,
        directionalSprites: is_array($entry['sprites'] ?? null) ? $entry['sprites'] : [],
        graphicalSprites: $graphicalSprites,
        graphicalSpriteId: 'npc:map:' . rawurlencode($mapId) . ':'
          . ($id !== '' ? 'id:' . rawurlencode($id) : 'entry:' . $index),
        assetRoot: $graphicalSprites === null ? null
          : ($this->gameScene->getGame()->getRendererRuntime()?->getAssetRoot() ?? getcwd() . '/assets'),
      );
    }

    return $npcs;
  }

  /** @param Npc[] $npcs */
  public function applyPreparedNpcs(array $npcs): void
  {
    $this->stopGraphicalAnimation();
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
      if ($sprites->layer < PresentationLayerPolicy::WORLD || $sprites->layer >= PresentationLayerPolicy::UI) {
        throw new InvalidArgumentException('Automatic Game world sprites require layers 0..999; UI layers are reserved.');
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

  private function isPresentationVisible(Npc $npc): bool
  {
    return $this->conditionsHold($npc->conditions) && !($this->gameScene->cinematicStage?->suppresses($npc) ?? false);
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
      if (! $npc->wanders) {
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
        $this->gameScene->camera->renderOnScreen([$npc->sprite], $npc->position);
      });
    }
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
      ! $this->gameScene->mapManager->canMoveTo($destinationX, $destinationY)
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
    $npc->position->x = $destinationX;
    $npc->position->y = $destinationY;
    $this->beginGraphicalStep($npc);
    $this->gameScene->cinematicStage?->subjectMoved($npc);
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

    $this->eraseNpc($npc);
    $npc->face($direction);
    $npc->stopGraphicalAnimation();
    $this->gameScene->cinematicStage?->subjectStopped($npc);
    $this->renderNpc($npc);

    return true;
  }

  /**
   * Returns the NPCs whose visibility conditions currently hold.
   *
   * @return Npc[] The visible NPCs.
   */
  public function visibleNpcs(): array
  {
    return array_values(array_filter(
      $this->npcs,
      fn(Npc $npc): bool => $this->conditionsHold($npc->conditions)
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
    $npc->position->x = $destinationX;
    $npc->position->y = $destinationY;
    $this->beginGraphicalStep($npc);
    $this->gameScene->cinematicStage?->subjectMoved($npc);
    $this->renderNpc($npc);
  }

  private function beginGraphicalStep(Npc $npc): void
  {
    if ($this->isPresentationVisible($npc)) {
      $npc->beginGraphicalStep();
    } else {
      $npc->stopGraphicalAnimation();
    }
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
    if ($this->gameScene->cinematicStage?->suppresses($npc) ?? false) {
      return;
    }
    $cells = MapCell::getSpanCells(TerminalText::displayWidth($npc->sprite));

    for ($column = 0; $column < $cells; $column++) {
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
