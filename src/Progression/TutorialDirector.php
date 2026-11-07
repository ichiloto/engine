<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Progression;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\Quests\QuestLog;
use InvalidArgumentException;

/** Owns a campaign's guided lesson across field and menu transitions. */
final class TutorialDirector
{
  public const string CONFIG_AUTOMATIC_PROMPTS = 'ui.tutorials.automatic_prompts';

  private ?TutorialDefinition $active = null;
  private int $stepIndex = 0;
  private bool $stepFocusApplied = false;
  private bool $practice = false;
  /** @var array<array-key, true> Failed scenes are retried after a new campaign load. */
  private array $failedFollowOns = [];

  /** @var array<array-key, bool> Last observed conditions during controlled play. */
  private array $automaticConditions = [];

  public function __construct(
    private readonly TutorialCatalog $catalog,
    private readonly GameState $state,
  ) {}

  public function queueScriptTutorial(string $id): void
  {
    $tutorial = $this->catalog->findTutorial($id);
    if ($tutorial?->start !== TutorialStartMode::SCRIPT) {
      throw new InvalidArgumentException('Unknown script-started tutorial ' . $id . '.');
    }

    if ($this->state->getTutorialPhase($id) === null) {
      $this->state->setTutorialPhase($id, TutorialPhase::PENDING);
    }
  }

  /** The owning field calls this after events, scene transitions and modal notices release control. */
  public function updateInStableField(bool $automaticPrompts, ?Party $party = null, ?QuestLog $quests = null): void
  {
    foreach ($this->catalog->definitions as $tutorial) {
      if ($tutorial->start !== TutorialStartMode::AUTOMATIC || $this->state->getTutorialPhase($tutorial->id) !== null) {
        continue;
      }

      $holds = WorldConditionEvaluator::allHold($tutorial->trigger, $this->state, $party, $quests);
      $previous = $this->automaticConditions[$tutorial->id] ?? null;
      $this->automaticConditions[$tutorial->id] = $holds;
      // Record when the player encounters the concept, independently of prompt visibility.
      if ($holds && $previous === false) {
        $this->state->setTutorialPhase($tutorial->id, TutorialPhase::PENDING);
      }
    }

    if ($this->active !== null) {
      return;
    }

    foreach ($this->catalog->definitions as $tutorial) {
      if ($this->state->getTutorialPhase($tutorial->id) !== TutorialPhase::PENDING
        || ($tutorial->start === TutorialStartMode::AUTOMATIC && !$automaticPrompts)
        || !$this->hasBoundControls($tutorial)) {
        continue;
      }
      if (WorldConditionEvaluator::allHold($tutorial->trigger, $this->state, $party, $quests)) {
        $this->beginTutorial($tutorial, false);
        return;
      }
    }
  }

  public function getActiveTutorial(): ?TutorialDefinition
  {
    return $this->active;
  }

  public function getStepForView(string $screen, string $view): ?TutorialStep
  {
    $step = $this->active?->steps[$this->stepIndex] ?? null;
    return $step !== null && $step->target['screen'] === $screen && $step->target['view'] === $view ? $step : null;
  }

  /** Each authored focus is applied once when its real target becomes available. */
  public function applyStepFocus(?TutorialStep $step, callable $focusTarget): bool
  {
    if ($step === null || !$step->focus || $this->stepFocusApplied
      || $step !== ($this->active?->steps[$this->stepIndex] ?? null)
      || !$focusTarget($step)) {
      return false;
    }

    $this->stepFocusApplied = true;
    return true;
  }

  /** Called by the screen that owned the operation, using its view before any transition. */
  public function reportOutcome(string $screen, string $view, string $outcome, ?string $subject = null): void
  {
    $step = $this->getStepForView($screen, $view);
    if ($step !== null && ($step->until['outcome'] ?? null) === $outcome
      && (!isset($step->until['subject']) || $step->until['subject'] === $subject)) {
      $this->advanceStep();
    }
  }

  public function evaluateStepCondition(string $screen, string $view, ?Party $party = null, ?QuestLog $quests = null): void
  {
    $step = $this->getStepForView($screen, $view);
    if ($step !== null && !isset($step->until['outcome'])
      && WorldConditionEvaluator::allHold([$step->until], $this->state, $party, $quests)) {
      $this->advanceStep();
    }
  }

  /** @return list<TutorialDefinition> Lessons encountered in this campaign, in source order. */
  public function getGuideEntries(): array
  {
    return array_values(array_filter($this->catalog->definitions,
      fn(TutorialDefinition $tutorial): bool => $this->state->getTutorialPhase($tutorial->id) !== null));
  }

  public function canStartPractice(TutorialDefinition $tutorial): bool
  {
    return $this->active === null && $tutorial->replay === TutorialReplayMode::PRACTICE
      && $this->state->getTutorialPhase($tutorial->id) !== null && $this->hasBoundControls($tutorial);
  }

  public function startPractice(string $id): bool
  {
    $tutorial = $this->catalog->findTutorial($id);
    if ($tutorial === null || !$this->canStartPractice($tutorial)) {
      return false;
    }

    $this->beginTutorial($tutorial, true);
    return true;
  }

  public function getSkippableTutorial(?Party $party = null, ?QuestLog $quests = null): ?TutorialDefinition
  {
    if ($this->active !== null) {
      return $this->active;
    }

    return array_find($this->catalog->definitions, fn(TutorialDefinition $tutorial): bool =>
      $tutorial->start === TutorialStartMode::SCRIPT
      && $this->state->getTutorialPhase($tutorial->id) === TutorialPhase::PENDING
      && !$this->hasBoundControls($tutorial)
      && WorldConditionEvaluator::allHold($tutorial->trigger, $this->state, $party, $quests));
  }

  public function skipTutorial(string $id, ?Party $party = null, ?QuestLog $quests = null): bool
  {
    $tutorial = $this->getSkippableTutorial($party, $quests);
    if ($tutorial?->id !== $id) {
      return false;
    }
    if ($this->active !== null) {
      return $this->skipActiveTutorial();
    }

    $this->state->finishTutorial($id, TutorialPhase::SKIPPED, $tutorial->onFinish !== null);
    return true;
  }

  public function skipActiveTutorial(): bool
  {
    if ($this->active === null) {
      return false;
    }

    $this->finishTutorial(TutorialPhase::SKIPPED);
    return true;
  }

  /** @return list<TutorialDefinition> Story follow-ons eligible to attempt in this campaign. */
  public function getPendingFollowOns(): array
  {
    return array_values(array_filter($this->catalog->definitions,
      fn(TutorialDefinition $tutorial): bool => $tutorial->onFinish !== null
        && !isset($this->failedFollowOns[$tutorial->id])
        && $this->state->hasPendingTutorialFollowOn($tutorial->id)));
  }

  public function acknowledgeFollowOn(string $id): void
  {
    $this->state->acknowledgeTutorialFollowOn($id);
  }

  public function deferFailedFollowOn(string $id): void
  {
    $this->failedFollowOns[$id] = true;
  }

  private function hasBoundControls(TutorialDefinition $tutorial): bool
  {
    return array_all($tutorial->steps, static fn(TutorialStep $step): bool => ActionHints::resolveControl($step->action) !== null);
  }

  private function beginTutorial(TutorialDefinition $tutorial, bool $practice): void
  {
    $this->active = $tutorial;
    $this->stepIndex = 0;
    $this->practice = $practice;
    $this->stepFocusApplied = false;
  }

  private function advanceStep(): void
  {
    $this->stepFocusApplied = false;
    if (++$this->stepIndex === count($this->active->steps)) {
      $this->finishTutorial(TutorialPhase::COMPLETED);
    }
  }

  private function finishTutorial(TutorialPhase $phase): void
  {
    $tutorial = $this->active;
    $practice = $this->practice;
    $this->active = null;
    $this->stepIndex = 0;
    $this->practice = false;
    if (!$practice) {
      $this->state->finishTutorial($tutorial->id, $phase, $tutorial->onFinish !== null);
    }
  }
}
