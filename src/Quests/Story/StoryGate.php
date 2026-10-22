<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Quests\Story;

use Ichiloto\Engine\Quests\Quest;

/**
 * A story flag the player must set to go on: a main quest's prerequisite,
 * or the condition of a door that turns the player back until it is set.
 */
final readonly class StoryGate
{
  /**
   * @param string $flag The story event or switch the gate needs.
   * @param Quest|null $quest The main quest that needs it to start.
   * @param string|null $map For a door, its map.
   * @param string|null $marker For a door, its event marker.
   */
  public function __construct(
    public string $flag,
    public ?Quest $quest = null,
    public ?string $map = null,
    public ?string $marker = null,
  )
  {
  }
}
