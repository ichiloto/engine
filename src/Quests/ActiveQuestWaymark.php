<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Quests;

/**
 * One waymark the player should currently follow: its quest, objective and
 * the spoiler-safe objective text that explains it.
 *
 * Its objective index is -1 when it leads to a quest's start rather than
 * one of its objectives: an authored start waymark ({@see Quest::$startWaymarks}),
 * or a waymark that fills a gap ({@see Story\StoryWayfinder}), leading where
 * nothing authored does.
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
    public bool $fillsGap = false,
  )
  {
  }

  /** Stable within a session; presentation identities derive from it. */
  public function getId(): string
  {
    return sprintf('%s%s:%d:%d', $this->fillsGap ? 'gap:' : '', $this->questId, $this->objectiveIndex, $this->waymarkIndex);
  }
}
