<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Quests;

/**
 * One waymark the player should currently follow: its quest, objective and
 * the spoiler-safe objective text that explains it.
 */
final readonly class ActiveQuestWaymark
{
  public function __construct(
    public string $questId,
    public int $objectiveIndex,
    public int $waymarkIndex,
    public QuestWaymark $waymark,
    public string $label,
    public bool $isOptionalQuest = false,
  )
  {
  }

  /** Stable within a session; presentation identities derive from it. */
  public function getId(): string
  {
    return sprintf('%s:%d:%d', $this->questId, $this->objectiveIndex, $this->waymarkIndex);
  }
}
