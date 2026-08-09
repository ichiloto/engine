<?php

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Core\WorldStateWriter;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Messaging\Dialogue\ConditionalDialogue;
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
 *     // Either a plain page list, or conditional variants where the first
 *     // matching entry is spoken (see ConditionalDialogue).
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
      // Pick what to say from the state of the world, so a character can
      // acknowledge what the player has actually done.
      $variant = ConditionalDialogue::select($this->dialogue, $gameScene->gameState, $gameScene->party);

      foreach ($variant['lines'] as $page) {
        show_text(
          strval($page['text'] ?? ''),
          strval($page['name'] ?? $this->name),
          charactersPerSecond: dialogue_speed()
        );
      }

      if (! empty($variant['script'])) {
        new EventInterpreter($gameScene)->run($variant['script']);
      }

      // A variant's own writes land before the NPC's, so "first time you
      // report back" state is recorded by the line that said it.
      WorldStateWriter::applyAll($variant['sets'], $gameScene->gameState);
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
    WorldStateWriter::applyAll($this->sets, $gameScene->gameState);
  }
}
