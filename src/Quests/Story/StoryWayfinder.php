<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Quests\Story;

use Closure;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Field\MapSubjectGates;
use Ichiloto\Engine\Quests\ActiveQuestWaymark;
use Ichiloto\Engine\Quests\Quest;
use Ichiloto\Engine\Quests\QuestLog;
use Ichiloto\Engine\Quests\QuestObjective;
use Ichiloto\Engine\Quests\QuestObjectiveType;
use Ichiloto\Engine\Quests\QuestWaymark;
use Ichiloto\Engine\Quests\QuestWaymarkSubject;
use Ichiloto\Engine\Util\Debug;

/**
 * Keeps the wayfinder leading on the story path when no authored waymark
 * does.
 *
 * Authored waymarks always win: while a main quest shows one (an objective
 * of an active quest, or a start waymark of one that can start), this adds
 * nothing. When none does and the story has
 * a next step, it leads to what takes that step, read from the same
 * {@see StoryIndex} the Editor's guidance check uses:
 *
 * - With a main quest active, each unfinished objective: what sets its flag,
 *   the map it asks for, the NPC it names, or what starts a battle with the
 *   enemy it names.
 * - With none active, each main quest one step away: what starts it once its
 *   prerequisites hold, or what sets the story flags it still needs. A quest
 *   still waiting on another quest, or on anything but story flags, is not
 *   the next step.
 *
 * Only what the player can set off now leads: an event or NPC page as its
 * map authors it ({@see MapSubjectGates}), never a finished one-shot. Every
 * fill is reported as a content gap to close with an authored waymark.
 * When nothing resolves, it shows nothing rather than guess, and reports
 * that too.
 */
final class StoryWayfinder
{
  private ?StoryIndex $index = null;
  /** @var array<string, true> Reports already made this session. */
  private array $reported = [];
  private readonly Closure $report;

  /**
   * @param Closure(): StoryIndex $readIndex Reads the project's story, once, when a gap first needs it.
   * @param Closure(): MapSubjectGates $readGates Reads when each map's events and NPCs can be used.
   * @param (Closure(string): void)|null $report Receives the content-gap diagnostics; the warning log by default.
   */
  public function __construct(private readonly Closure $readIndex, private readonly Closure $readGates, ?Closure $report = null)
  {
    $this->report = $report ?? static fn(string $message) => Debug::warn($message);
  }

  /**
   * The waymarks to add so the story path is never without one.
   *
   * @param list<ActiveQuestWaymark> $shown The authored waymarks shown now.
   * @param array<int|string, Quest> $quests Every quest, in authored order.
   * @return list<ActiveQuestWaymark> Empty when an authored waymark leads on the story path, the story is
   *   finished, or nothing can be resolved.
   */
  public function findGapWaymarks(array $shown, array $quests, QuestLog $log, GameState $state, ?Party $party = null): array
  {
    if (array_any($shown, static fn(ActiveQuestWaymark $entry): bool => !$entry->isOptionalQuest)) {
      return [];
    }
    $main = array_values(array_filter($quests, static fn(Quest $quest): bool => !$quest->isOptional));
    $unfinished = array_values(array_filter($main, static fn(Quest $quest): bool => !$log->isCompleted($quest->id)));
    if ($unfinished === []) {
      return [];
    }

    $index = $this->index ??= ($this->readIndex)();
    $gates = ($this->readGates)();
    $isUsable = static fn(StorySource $source): bool => match ($source->subject) {
      QuestWaymarkSubject::EVENT => $gates->allowsEvent($source->map, $source->subjectId, $state, $party, $log),
      QuestWaymarkSubject::NPC => $gates->allowsNpcPage($source->map, $source->subjectId, $source->page ?? 0, $state, $party, $log),
      default => true,
    };
    $active = array_values(array_filter($unfinished, static fn(Quest $quest): bool => $log->isActive($quest->id)));
    $steps = $active !== []
      ? self::findObjectiveSteps($index, $active, $log, $state, $party, $isUsable)
      : self::findStartSteps($index, $unfinished, $log, $state, $party, $isUsable);

    // Where the author closes the gap: the active quest's objectives, or, between quests, the quest before the step or a start waymark.
    $fix = $active !== []
      ? 'Give the active main quest an objective with a waymark there'
      : 'Lead there with an objective of the main quest before this step, or a start waymark of a main quest that can start then';
    if ($steps === []) {
      $this->reportOnce(sprintf('The wayfinder has nothing to lead with on the story path: %s. It shows nothing rather '
        . 'than guess. %s.', $active !== [] ? self::describeObjectives($active, $log)
        : self::describePrerequisites($unfinished[0], $log, $state, $party), $fix));
      return [];
    }

    $waymarks = [];
    $taken = [];
    $reasons = [];
    foreach ($steps as $step) {
      foreach ($step['targets'] as [$waymark, $where]) {
        $key = $waymark->map . '|' . $waymark->subject?->value . '|' . $waymark->subjectId;
        if (isset($taken[$key])) { continue; }
        $taken[$key] = true;
        $waymarks[] = new ActiveQuestWaymark($step['quest']->id, $step['objective'] ?? -1, count($waymarks), $waymark,
          $step['label'], fillsGap: true);
        $reasons[] = sprintf('%s, which %s', $where, $step['reason']);
      }
    }
    $this->reportOnce(sprintf('The wayfinder filled a gap on the story path: %s, so it leads to %s. %s, so the wayfinder '
      . 'does not rely on this.', $active !== [] ? 'no objective of an active main quest shows a waymark'
      : 'no main quest is active and no start waymark of one leads',
      implode('; ', $reasons), $fix));

    return $waymarks;
  }

  /**
   * The unfinished objectives of the active main quests that something usable now advances.
   *
   * @param list<Quest> $active
   * @param Closure(StorySource): bool $isUsable
   * @return list<array{quest: Quest, objective: ?int, label: string, reason: string, targets: list<array{QuestWaymark, string}>}>
   */
  private static function findObjectiveSteps(StoryIndex $index, array $active, QuestLog $log, GameState $state, ?Party $party,
    Closure $isUsable): array
  {
    $steps = [];
    foreach ($active as $quest) {
      $progress = $log->getProgress($quest->id);
      foreach ($quest->objectives as $objectiveIndex => $objective) {
        if (($progress[$objectiveIndex] ?? 0) >= $objective->quantity) { continue; }
        [$sources, $reason] = self::findObjectiveSources($index, $objective);
        $targets = $objective->type === QuestObjectiveType::REACH_MAP
          ? [[new QuestWaymark($objective->target), $objective->target]]
          : self::describeTargets(array_filter($sources, $isUsable));
        if ($targets !== []) {
          $steps[] = ['quest' => $quest, 'objective' => $objectiveIndex, 'label' => $objective->displayDescription($state, $party),
            'reason' => sprintf('%s for "%s"', $reason, $quest->id), 'targets' => $targets];
        }
      }
    }

    return $steps;
  }

  /**
   * @return array{list<StorySource>, string} What advances the objective, and how, for diagnostics.
   */
  private static function findObjectiveSources(StoryIndex $index, QuestObjective $objective): array
  {
    return match ($objective->type) {
      QuestObjectiveType::FLAG => [$index->findSetters($objective->target), sprintf('sets "%s"', $objective->target)],
      QuestObjectiveType::TALK_TO => [$index->findSpeakers($objective->target), sprintf('is %s to talk to', $objective->target)],
      QuestObjectiveType::DEFEAT => [$index->findBattles($objective->target), sprintf('battles %s', $objective->target)],
      QuestObjectiveType::REACH_MAP => [[], 'is the map to reach'],
      default => [[], ''],
    };
  }

  /**
   * The main quests one step away and what usable now takes that step.
   *
   * @param list<Quest> $unfinished Unfinished main quests, none active.
   * @param Closure(StorySource): bool $isUsable
   * @return list<array{quest: Quest, objective: ?int, label: string, reason: string, targets: list<array{QuestWaymark, string}>}>
   */
  private static function findStartSteps(StoryIndex $index, array $unfinished, QuestLog $log, GameState $state, ?Party $party,
    Closure $isUsable): array
  {
    $label = get_engine_message('quest.story_waymark');
    $steps = [];
    foreach ($unfinished as $quest) {
      $unmet = self::findUnmetConditions($quest->prerequisites, $log, $state, $party);
      if ($unmet === []) {
        $targets = self::describeTargets(array_filter($index->findStarters($quest->id), $isUsable));
        if ($targets !== []) {
          $steps[] = ['quest' => $quest, 'objective' => null, 'label' => $label,
            'reason' => sprintf('starts "%s"', $quest->id), 'targets' => $targets];
        }
        continue;
      }
      $flags = StoryIndex::readRequiredFlags($unmet);
      if (count($flags) !== count($unmet)) {
        // It waits on another quest, or on something no story flag gives.
        continue;
      }
      $targets = [];
      foreach ($flags as $flag) {
        $found = self::describeTargets(array_filter($index->findSetters($flag), $isUsable));
        if ($found === []) {
          // A flag nothing usable now sets: this quest is further on.
          continue 2;
        }
        array_push($targets, ...$found);
      }
      $steps[] = ['quest' => $quest, 'objective' => null, 'label' => $label,
        'reason' => sprintf('sets %s, which "%s" needs', '"' . implode('", "', $flags) . '"', $quest->id), 'targets' => $targets];
    }

    return $steps;
  }

  /**
   * @param iterable<StorySource> $sources
   * @return list<array{QuestWaymark, string}>
   */
  private static function describeTargets(iterable $sources): array
  {
    $targets = [];
    foreach ($sources as $source) {
      $targets[] = [$source->createWaymark(), $source->describe()];
    }

    return $targets;
  }

  /**
   * @param array<int, mixed> $conditions
   * @return list<array<string, mixed>> The conditions that do not hold now.
   */
  private static function findUnmetConditions(array $conditions, QuestLog $log, GameState $state, ?Party $party): array
  {
    return array_values(array_filter($conditions, static fn(mixed $condition): bool
      => is_array($condition) && !WorldConditionEvaluator::allHold([$condition], $state, $party, $log)));
  }

  /** @param list<Quest> $active */
  private static function describeObjectives(array $active, QuestLog $log): string
  {
    $open = [];
    foreach ($active as $quest) {
      $progress = $log->getProgress($quest->id);
      foreach ($quest->objectives as $index => $objective) {
        if (($progress[$index] ?? 0) < $objective->quantity) {
          $open[] = sprintf('"%s" objective %d (%s %s)', $quest->id, $index + 1, $objective->type->value, $objective->target);
        }
      }
    }

    return sprintf('nothing usable now advances %s', implode(', ', $open));
  }

  private static function describePrerequisites(Quest $quest, QuestLog $log, GameState $state, ?Party $party): string
  {
    $unmet = self::findUnmetConditions($quest->prerequisites, $log, $state, $party);

    return $unmet === []
      ? sprintf('nothing usable now starts "%s"', $quest->id)
      : sprintf('"%s" needs %s, and nothing usable now provides it', $quest->id, implode(', ', array_map(
        static fn(array $condition): string => sprintf('%s%s "%s"', ($condition['negate'] ?? false) ? 'no ' : '',
          strval($condition['type'] ?? ''), strval($condition['name'] ?? '')), $unmet)));
  }

  private function reportOnce(string $message): void
  {
    if (!isset($this->reported[$message])) {
      $this->reported[$message] = true;
      ($this->report)($message);
    }
  }
}
