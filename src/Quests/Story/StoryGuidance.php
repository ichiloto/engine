<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Quests\Story;

use Closure;
use Ichiloto\Engine\Quests\Quest;
use Ichiloto\Engine\Quests\QuestObjective;
use Ichiloto\Engine\Quests\QuestObjectiveType;
use Ichiloto\Engine\Quests\QuestWaymark;

/**
 * Checks, from authored data alone, that the wayfinder always has an
 * authored waymark to lead with along the story.
 *
 * Waymarks, and the edge arrows and objective marks drawn from them, come
 * from the objectives of active quests and the start waymarks of quests that
 * can start ({@see Quest::$startWaymarks}). So every step the player must
 * take to go on needs guidance of a main quest that leads to it:
 *
 * - A gate: a story flag a main quest needs to start, or a door waits for.
 *   An objective leads to it when it tracks the flag with a waymark, or when
 *   its waymark points at what sets the flag (an event or NPC page, or the
 *   event or map where the battle that sets it is fought).
 * - A main quest's start: what starts it (an event or NPC page, or where a
 *   battle that starts it is fought). An objective of a main quest active
 *   before it leads to it when its waymark points there.
 *
 * A start waymark shows only until its own quest starts, so it leads to a
 * step through what starts its quest: it counts when it points at something
 * that both starts its quest and takes the step. The quest's own start is
 * the plain case; a gate's flag set where the quest is handed over is the
 * other. When everything that starts its quest waits for the step (the
 * quest's giver speaks only once a flag is set, say), the quest cannot start
 * before the step, so its start waymarks lead to whatever takes the step:
 * the way to a quest's start can pass through the step that opens it.
 *
 * Guidance must be able to show before the step is taken: its quest cannot
 * need the step to start, its objectives cannot belong to a quest that
 * starts only where the step is taken or only after it, and its waymark
 * cannot wait for the step. Optional quests guide nothing on the story path
 * and gate nothing on it.
 */
final class StoryGuidance
{
  private function __construct()
  {
  }

  /**
   * The gates no guidance of a main quest leads to.
   *
   * @param array<int|string, Quest> $quests The project's quests.
   * @return list<StoryGate>
   */
  public static function findUnguidedGates(array $quests, StoryIndex $index): array
  {
    $main = self::filterMainQuests($quests);
    $gates = [];
    foreach ($main as $quest) {
      foreach (StoryIndex::readRequiredFlags($quest->prerequisites) as $flag) {
        $gates[] = new StoryGate($flag, quest: $quest);
      }
    }

    return array_values(array_filter([...$gates, ...$index->doors],
      static fn(StoryGate $gate): bool => !self::isLedTo($gate->flag, $main, $index)));
  }

  /**
   * The main quests whose start no guidance of a main quest leads to,
   * including those nothing in the project starts.
   *
   * @param array<int|string, Quest> $quests The project's quests.
   * @return list<Quest>
   */
  public static function findUnguidedStarts(array $quests, StoryIndex $index): array
  {
    $main = self::filterMainQuests($quests);

    return array_values(array_filter($main, static fn(Quest $quest): bool => !self::isStartLedTo($quest, $main, $index)));
  }

  /**
   * Whether guidance of a main quest leads to a story flag before it is set.
   *
   * @param list<Quest> $mainQuests
   */
  public static function isLedTo(string $flag, array $mainQuests, StoryIndex $index): bool
  {
    return self::isGuided($index->findSetters($flag),
      static fn(mixed $conditions): bool => in_array($flag, StoryIndex::readRequiredFlags($conditions), true),
      static fn(StorySource $source): bool => isset($source->effects->flags[$flag]),
      static fn(QuestObjective $objective): bool => $objective->type === QuestObjectiveType::FLAG && $objective->matches($flag),
      $mainQuests, $index);
  }

  /**
   * Whether guidance of a main quest leads to what starts a quest before it
   * starts.
   *
   * @param list<Quest> $mainQuests
   */
  public static function isStartLedTo(Quest $quest, array $mainQuests, StoryIndex $index): bool
  {
    $id = $quest->id;

    return self::isGuided($index->findStarters($id),
      static fn(mixed $conditions): bool => self::needsQuestStarted($id, $conditions),
      static fn(StorySource $source): bool => isset($source->effects->quests[$id]),
      static fn(): bool => false,
      $mainQuests, $index);
  }

  /**
   * Whether a start waymark or an objective waymark of a main quest leads
   * to a step before it is taken.
   *
   * @param list<StorySource> $targets What takes the step.
   * @param Closure(mixed): bool $waitsForStep Whether conditions hold only once the step is taken.
   * @param Closure(StorySource): bool $takesStep Whether setting a source off takes the step.
   * @param Closure(QuestObjective): bool $tracksStep Whether an objective tracks the step itself.
   * @param list<Quest> $mainQuests
   */
  private static function isGuided(array $targets, Closure $waitsForStep, Closure $takesStep, Closure $tracksStep,
    array $mainQuests, StoryIndex $index): bool
  {
    $leads = static fn(QuestWaymark $waymark, bool $tracks, array $through): bool => !$waitsForStep($waymark->conditions)
      && ($tracks || array_any($through, static fn(StorySource $source): bool => $source->isMarkedBy($waymark)));
    foreach ($mainQuests as $quest) {
      // A quest that needs the step shows neither its start waymarks nor its objectives before it.
      if ($waitsForStep($quest->prerequisites)) {
        continue;
      }
      // Start waymarks show until the quest starts, so they lead through what starts it, and to anything that
      // takes the step when everything that starts the quest waits for the step.
      $id = $quest->id;
      $starts = $index->findStarters($id);
      $startsAfterStep = $starts !== [] && array_all($starts, static fn(StorySource $source): bool => $waitsForStep($source->conditions));
      $through = $startsAfterStep ? $targets
        : array_values(array_filter($targets, static fn(StorySource $source): bool => isset($source->effects->quests[$id])));
      if (array_any($quest->startWaymarks, static fn(QuestWaymark $waymark): bool => $leads($waymark, false, $through))) {
        return true;
      }
      // Objectives show once the quest is active, which is never before the step where it starts only there or only after it.
      if ($starts !== [] && ($startsAfterStep || array_all($starts, $takesStep))) {
        continue;
      }
      foreach ($quest->objectives as $objective) {
        $tracks = $tracksStep($objective);
        if (array_any($objective->waymarks, static fn(QuestWaymark $waymark): bool => $leads($waymark, $tracks, $targets))) {
          return true;
        }
      }
    }

    return false;
  }

  /** Whether conditions need a quest active or completed, so hold only once it has started. */
  private static function needsQuestStarted(string $questId, mixed $conditions): bool
  {
    return array_any(is_array($conditions) ? $conditions : [], static fn(mixed $condition): bool => is_array($condition)
      && ($condition['type'] ?? null) === 'quest' && !($condition['negate'] ?? false)
      && is_string($condition['name'] ?? null) && trim($condition['name']) === $questId);
  }

  /**
   * @param array<int|string, Quest> $quests
   * @return list<Quest>
   */
  private static function filterMainQuests(array $quests): array
  {
    return array_values(array_filter($quests, static fn(Quest $quest): bool => !$quest->isOptional));
  }
}
