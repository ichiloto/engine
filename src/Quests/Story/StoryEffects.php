<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Quests\Story;

/**
 * What a piece of authored content can do to the story when it runs: the
 * story flags (story events and switches turned on) it can set, the quests
 * it can start and the troops it can battle. "Can": branches are followed
 * whatever their conditions, so these are possibilities, never promises.
 */
final class StoryEffects
{
  /**
   * @param array<string, true> $flags Story events and switches it can set, by name.
   * @param array<string, true> $quests Quests it can start, by id.
   * @param array<string, true> $troops Troops it can battle, by name.
   */
  public function __construct(
    public readonly array $flags = [],
    public readonly array $quests = [],
    public readonly array $troops = [],
  )
  {
  }

  /** These effects together with another's. */
  public function withEffects(self $other): self
  {
    return new self($this->flags + $other->flags, $this->quests + $other->quests, $this->troops + $other->troops);
  }

  public bool $isEmpty {
    get => $this->flags === [] && $this->quests === [] && $this->troops === [];
  }
}
