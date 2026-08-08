<?php

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Scenes\Game\GameScene;

/**
 * A field NPC: a sprite on the map the player can talk to.
 *
 * Authored per map under an `npcs` block:
 *
 * ```php
 * 'npcs' => [
 *   [
 *     'name' => 'Mom',
 *     'sprite' => '👩',
 *     'x' => 23, 'y' => 5,
 *     'movement' => 'fixed',                     // or 'wander'
 *     'wanderArea' => ['x' => 20, 'y' => 4, 'width' => 6, 'height' => 3],
 *     'dialogue' => [['name' => 'Mom', 'text' => '…'], …],
 *     'script' => [ …event commands… ],          // replaces dialogue when present
 *     'conditions' => [ …trigger conditions… ],  // NPC only appears while these hold
 *     'sets' => [ …trigger sets… ],              // applied after every conversation
 *   ],
 * ],
 * ```
 *
 * @package Ichiloto\Engine\Field
 */
class Npc
{
  /**
   * @var float The next time this NPC may take a wander step.
   */
  public float $nextWanderTime = 0.0;

  /**
   * @param string $name The NPC's name (talk-to quests match it).
   * @param string $sprite The map glyph.
   * @param Vector2 $position The world position.
   * @param bool $wanders True when the NPC takes random steps.
   * @param array{x: int, y: int, width: int, height: int}|null $wanderArea Bounds for wandering; null wanders freely.
   * @param array<int, array<string, mixed>> $dialogue Dialogue pages (name + text).
   * @param array<int, array<string, mixed>> $script Event-script commands; replaces dialogue when non-empty.
   * @param array<int, array<string, mixed>> $conditions Visibility conditions (trigger shapes).
   * @param array<int, array<string, mixed>> $sets World-state writes applied after each conversation.
   */
  public function __construct(
    protected(set) string $name,
    protected(set) string $sprite,
    protected(set) Vector2 $position,
    protected(set) bool $wanders = false,
    protected(set) ?array $wanderArea = null,
    protected(set) array $dialogue = [],
    protected(set) array $script = [],
    protected(set) array $conditions = [],
    protected(set) array $sets = [],
  )
  {
  }

  /**
   * Talks to the NPC: plays its script or dialogue, applies its `sets`,
   * and records the conversation for talk-to quests.
   *
   * @param GameScene $gameScene The running game scene.
   * @return void
   */
  public function talk(GameScene $gameScene): void
  {
    if (! empty($this->script)) {
      new EventInterpreter($gameScene)->run($this->script);
    } else {
      foreach ($this->dialogue as $page) {
        if (is_array($page)) {
          show_text(
            strval($page['text'] ?? ''),
            strval($page['name'] ?? $this->name),
            charactersPerSecond: dialogue_speed()
          );
        }
      }
    }

    $this->applySets($gameScene);
    QuestManager::current()?->recordTalkTo($this->name);
  }

  /**
   * Determines whether a wander step stays inside the NPC's area.
   *
   * @param int $x The destination x.
   * @param int $y The destination y.
   * @return bool True when the destination is allowed.
   */
  public function allowsWanderTo(int $x, int $y): bool
  {
    if ($this->wanderArea === null) {
      return true;
    }

    return $x >= $this->wanderArea['x']
      && $x < $this->wanderArea['x'] + $this->wanderArea['width']
      && $y >= $this->wanderArea['y']
      && $y < $this->wanderArea['y'] + $this->wanderArea['height'];
  }

  /**
   * Applies the NPC's world-state writes.
   *
   * @param GameScene $gameScene The running game scene.
   * @return void
   */
  protected function applySets(GameScene $gameScene): void
  {
    foreach ($this->sets as $set) {
      if (! is_array($set)) {
        continue;
      }

      $name = trim(strval($set['name'] ?? ''));

      if ($name === '') {
        continue;
      }

      match (strval($set['type'] ?? '')) {
        'switch' => $gameScene->gameState->setSwitch($name, (bool) ($set['value'] ?? true)),
        'event' => $gameScene->gameState->recordStoryEvent($name),
        'variable' => strval($set['op'] ?? 'set') === 'add'
          ? $gameScene->gameState->addToVariable($name, is_numeric($set['value'] ?? 1) ? $set['value'] + 0 : 1)
          : $gameScene->gameState->setVariable($name, $set['value'] ?? 0),
        'quest' => QuestManager::current()?->acceptQuest($name),
        default => null,
      };
    }
  }
}
