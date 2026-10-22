<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Quests\Story;

use Ichiloto\Engine\Quests\QuestWaymark;
use Ichiloto\Engine\Quests\QuestWaymarkSubject;

/**
 * Something on a map the player sets off, which a waymark can point at: a
 * map event, one page of an NPC (each page runs on its own), or the map as a
 * whole for the troops met in its random encounters.
 */
final readonly class StorySource
{
  /**
   * @param string $map The map id.
   * @param QuestWaymarkSubject|null $subject EVENT or NPC; null for the map's encounters.
   * @param string $subjectId The event marker or NPC id; empty for the map's encounters.
   * @param int|null $page The NPC page index, as {@see \Ichiloto\Engine\Field\NpcPages} numbers it.
   * @param string $name The NPC's name, which `talk_to` objectives match.
   * @param StoryEffects $effects What setting it off can do.
   */
  public function __construct(
    public string $map,
    public ?QuestWaymarkSubject $subject,
    public string $subjectId,
    public StoryEffects $effects,
    public ?int $page = null,
    public string $name = '',
  )
  {
  }

  /**
   * Whether a waymark leads the player here: it marks this event or NPC, or,
   * for a map's encounters, it leads anywhere on the map.
   */
  public function isMarkedBy(QuestWaymark $waymark): bool
  {
    return $waymark->map === $this->map
      && ($this->subject === null || ($waymark->subject === $this->subject && $waymark->subjectId === $this->subjectId));
  }

  /** A waymark that leads the player here. */
  public function createWaymark(): QuestWaymark
  {
    return new QuestWaymark($this->map, $this->subject, $this->subjectId);
  }

  /** Where it is, for diagnostics: "town event B", "home NPC mother page 2", "road encounters". */
  public function describe(): string
  {
    return match ($this->subject) {
      null => "{$this->map} encounters",
      QuestWaymarkSubject::NPC => sprintf('%s NPC %s page %d', $this->map, $this->subjectId, ($this->page ?? 0) + 1),
      default => "{$this->map} {$this->subject->value} {$this->subjectId}",
    };
  }
}
