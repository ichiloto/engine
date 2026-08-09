<?php

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Scenes\Game\GameScene;

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
    $this->npcs = [];

    foreach ($entries as $entry) {
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

      $this->npcs[] = new Npc(
        $name,
        strval($entry['sprite'] ?? '@'),
        new Vector2(intval($entry['x']), intval($entry['y'])),
        strval($entry['movement'] ?? 'fixed') === 'wander',
        $wanderArea,
        array_values(array_filter((array) ($entry['dialogue'] ?? []), is_array(...))),
        array_values(array_filter((array) ($entry['script'] ?? []), is_array(...))),
        array_values(array_filter((array) ($entry['conditions'] ?? []), is_array(...))),
        array_values(array_filter((array) ($entry['sets'] ?? []), is_array(...))),
      );
    }
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
      $this->gameScene->camera->renderOnScreen([$npc->sprite], $npc->position);
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
    $npc->position->x = $destinationX;
    $npc->position->y = $destinationY;
    $this->gameScene->camera->renderOnScreen([$npc->sprite], $npc->position);
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
