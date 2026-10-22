<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Quests\Story;

use Ichiloto\Engine\Quests\Quest;
use Ichiloto\Engine\Quests\QuestObjectiveType;

/**
 * Checks, from authored data alone, that the wayfinder always has an
 * objective to lead with along the story.
 *
 * Waymarks, and the edge arrows and objective marks drawn from them, come
 * from the objectives of active quests. So every gate the player must open
 * to go on (a story flag a main quest needs to start, or a door waits for)
 * needs an objective of a main quest that leads to it: one that tracks the
 * flag with a waymark, or whose waymark leads to what sets the flag (an
 * event or NPC page, or the event or map where the battle that sets it is
 * fought). It must be able to lead before the flag is set: its quest cannot
 * need the flag to start, nor start only where the flag is set, and its
 * waymark cannot wait for the flag. Optional quests guide nothing on the
 * story path and gate nothing on it.
 */
final class StoryGuidance
{
  private function __construct()
  {
  }

  /**
   * The gates no objective of a main quest leads to.
   *
   * @param array<int|string, Quest> $quests The project's quests.
   * @return list<StoryGate>
   */
  public static function findUnguidedGates(array $quests, StoryIndex $index): array
  {
    $main = array_values(array_filter($quests, static fn(Quest $quest): bool => !$quest->isOptional));
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
   * Whether an objective of a main quest leads to a flag before it is set.
   *
   * @param list<Quest> $mainQuests
   */
  public static function isLedTo(string $flag, array $mainQuests, StoryIndex $index): bool
  {
    $setters = $index->findSetters($flag);
    foreach ($mainQuests as $quest) {
      // A quest that needs the flag, or starts only where the flag is set, is never active before it.
      $starts = $index->findStarters($quest->id);
      if (in_array($flag, StoryIndex::readRequiredFlags($quest->prerequisites), true)
        || ($starts !== [] && array_all($starts, static fn(StorySource $source): bool => isset($source->effects->flags[$flag])))) {
        continue;
      }
      foreach ($quest->objectives as $objective) {
        $tracksFlag = $objective->type === QuestObjectiveType::FLAG && $objective->matches($flag);
        foreach ($objective->waymarks as $waymark) {
          if (in_array($flag, StoryIndex::readRequiredFlags($waymark->conditions), true)) {
            continue;
          }
          if ($tracksFlag || array_any($setters, static fn(StorySource $source): bool => $source->isMarkedBy($waymark))) {
            return true;
          }
        }
      }
    }

    return false;
  }
}
