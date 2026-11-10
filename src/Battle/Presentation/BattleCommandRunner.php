<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Closure;
use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\Actions\ExecutionEligibility;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnStateExecutionContext;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Battle\Resolution\CombatActionResult;
use Ichiloto\Engine\Battle\Resolution\CombatTargetResult;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\UI\Accessibility;
use Ichiloto\Engine\Util\Debug;

/** Owns one action's presentation lifetime, not its combat rules or the battle clock. */
final class BattleCommandRunner
{
  public private(set) BattleCommandPlayback $playback;
  private Closure $formatResult;
  private Closure $presentCue;
  private array $previousVitals = [];
  private ?CombatActionResult $commandResult = null;
  public ?CombatActionResult $result { get => $this->commandResult; }
  private bool $reportedFailure = false;
  private bool $hidControls = false;
  private bool $begun = false;
  private bool $disposed = false;
  private ?string $executionRefusal = null;
  private bool $resultsPresented = false;
  private bool $resultsPending = false;
  /** @var list<array{target: CharacterInterface, frame: int, clear: bool}> */
  private array $scheduledResults = [];
  private bool $announcementVisible = false;

  /** @param list<CharacterInterface> $targets */
  public function __construct(
    private readonly TurnStateExecutionContext $context,
    CharacterInterface $actor,
    array $targets,
    private readonly ?BattleAction $action,
    private readonly string $announcement,
    BattleTurnTimings $timings,
    ?CompiledEffectTimeline $source,
    ?CompiledEffectTimeline $target,
    callable $resolve,
    callable $formatResult,
    callable $presentCue,
    ?CompiledEffectTimeline $terminalTarget = null,
    bool $resultsOnly = false,
    float $targetScale = \Ichiloto\Engine\Animations\EffectScale::DEFAULT,
    \Ichiloto\Engine\Animations\SourceEffectAnchor $sourceEffectAnchor = \Ichiloto\Engine\Animations\SourceEffectAnchor::SOURCE,
    \Ichiloto\Engine\Animations\ActionMovement $movement = \Ichiloto\Engine\Animations\ActionMovement::STEP,
    ?int $animationId = null,
    ?\Ichiloto\Engine\Animations\FlashStepTiming $flashStep = null,
  ) {
    $this->formatResult = Closure::fromCallable($formatResult);
    $this->presentCue = Closure::fromCallable($presentCue);
    foreach ([$actor, ...$targets] as $battler) {
      $this->previousVitals[spl_object_id($battler)] = [$battler->stats->currentHp, $battler->stats->currentMp];
    }
    $pose = $resultsOnly ? BattlePoseRole::getRestingRole($actor) : BattlePoseRole::getForAction($action);
    $travel = null;
    $travelFailure = null;
    if (!$resultsOnly && !Accessibility::prefersReducedMotion() && $this->context->ui->usesGraphicalField()
      && $movement->usesTargetRoute() && $action !== null) {
      try { $travel = $this->context->ui->planActionTravel($actor, $targets, $action, $animationId, $movement); }
      catch (\InvalidArgumentException|\RuntimeException $error) {
        $travelFailure = $error;
        $travel = new BattleTravelPlan([], 0, 0, 1, 600, 900, BattleTravelFailure::PRESENTATION_UNAVAILABLE);
      }
      if ($travel !== null && !$travel->isPossible) {
        $travelFailure ??= new \RuntimeException('Battle target travel could not reach contact: ' . $travel->failure->value);
      }
    }
    $actionPoseOverride = $resultsOnly || $animationId === null || !$this->context->ui->usesGraphicalField()
      ? null : $this->context->ui->getActionPose($actor, $pose, $animationId);
    $flashStep = $travel?->isPossible ? $flashStep : null;
    if ($flashStep !== null && !$flashStep->supportsPose($actionPoseOverride)) {
      $travelFailure = new \RuntimeException('Flash step requires action transit cells and a planted first frame.');
      $flashStep = $travel = null;
    }
    $movePose = $flashStep === null ? $this->context->ui->getActionPose($actor, BattlePoseRole::MOVE) : null;
    $returnPose = $flashStep === null ? $this->context->ui->getActionPose($actor, BattlePoseRole::RETURNING) : null;
    $movementPoses = !$resultsOnly && !Accessibility::prefersReducedMotion() && ($movePose !== null || $returnPose !== null);
    $this->playback = new BattleCommandPlayback(new BattleCommandTimeline($timings, $source, $target, $terminalTarget, $resultsOnly,
      showAnnouncement: $announcement !== '', targetScale: $targetScale, sourceEffectAnchor: $sourceEffectAnchor,
      actionPose: $resultsOnly ? null : $this->context->ui->getActionPose($actor, $pose),
      movementPoses: $movementPoses, travel: $travel,
      movePose: $movementPoses ? $movePose : null, returnPose: $movementPoses ? $returnPose : null,
      projectedRange: isset($target->defaults['rangeTiming']) ? $this->context->ui->measureActionRange($actor, $targets) : null,
      actionPoseOverride: $actionPoseOverride, flashStep: $flashStep, targetCount: count($targets)),
      $actor, $targets, $pose,
      function () use ($resolve): void {
        if (($refusal = $this->getExecutionRefusal()) !== null) {
          $this->executionRefusal = $refusal;
          $this->playback->cancel();
          return;
        }
        // Reusable actions retain history; only a result produced by this resolution belongs to this command.
        $previousResult = $this->action?->lastResult;
        $resolve();
        $result = $this->action?->lastResult;
        $this->commandResult = $result !== $previousResult ? $result : null;
      }, $this->handleCue(...), EnemyDefeatStyle::createFromConfig());
    if ($travelFailure !== null) { $this->playback->recordPresentationFailure($travelFailure); }
  }

  public function begin(): void
  {
    if ($this->begun || $this->disposed) { return; }
    $this->begun = true;
    if (($refusal = $this->getExecutionRefusal()) !== null) {
      $this->beginRefusal($refusal);
      return;
    }
    if ($this->announcement === '') { $this->context->ui->hideMessage(); }
    $this->context->ui->bindCommandPosePoints($this->playback);
    $this->context->ui->fieldWindow->setCommandPlayback($this->playback);
    $this->playback->begin();
  }

  public function update(float $seconds): void
  {
    if ($this->disposed) { return; }
    $this->playback->update($seconds);
    $this->showScheduledResults($this->playback->session->currentFrame);
    if ($this->executionRefusal !== null && $this->playback->isCancelled) {
      $this->beginRefusal($this->executionRefusal);
    }
    if (!$this->reportedFailure && $this->playback->presentationFailure !== null) {
      Debug::warn('Battle command presentation failed: ' . $this->playback->presentationFailure->getMessage());
      $this->reportedFailure = true;
    }
  }

  public function dispose(): void
  {
    $this->disposed = true;
    $this->scheduledResults = [];
    $this->announcementVisible = false;
    $this->playback->cancel();
    $field = $this->context->ui->fieldWindow;
    if ($field->getCommandPlayback() === $this->playback) {
      foreach ([fn() => $field->setCommandPlayback(null), $field->clearBattleFlash(...), $field->clearSummonShake(...),
        $field->clearMagicCastEffects(...), $field->clearStatChangePopups(...),
        $field->clearTargetIndicators(...), $this->context->ui->hideMessage(...)] as $cleanup) {
        try { $cleanup(); }
        catch (\Throwable $error) { Debug::warn('Battle command cleanup failed: ' . $error->getMessage()); }
      }
      if ($this->hidControls) {
        $this->hidControls = false;
        try { $this->context->ui->showControls(); }
        catch (\Throwable $error) { Debug::warn('Battle command control cleanup failed: ' . $error->getMessage()); }
      }
    }
  }

  private function getExecutionRefusal(): ?string
  {
    return $this->action instanceof ExecutionEligibility
      ? $this->action->getExecutionRefusal($this->playback->actor) : null;
  }

  private function beginRefusal(string $refusal): void
  {
    $this->dispose();
    $this->disposed = false;
    $this->executionRefusal = $refusal;
    $this->resultsPresented = $this->resultsPending = false;
    $timings = new BattleTurnTimings(0, 0, 0, 0, 0, $this->context->ui->getPacing()->getMessageDurationSeconds(), 0);
    $this->playback = new BattleCommandPlayback(new BattleCommandTimeline($timings, resultsOnly: true),
      $this->playback->actor, $this->playback->targets, BattlePoseRole::getRestingRole($this->playback->actor),
      static function (): void {}, $this->handleCue(...));
    $this->context->ui->fieldWindow->setCommandPlayback($this->playback);
    $this->playback->begin();
  }

  private function handleCue(array $cue): void
  {
    if ($this->playback->isCancelled) { return; }
    $this->showScheduledResults($cue['frame']);
    if ($cue['type'] === 'enemyDefeated') {
      if ($this->playback->defeatStyle->audio) {
        $this->context->game->audioManager->playSystemSound(\Ichiloto\Engine\Audio\Enumerations\SystemSound::ENEMY_COLLAPSE);
      }
      return;
    }
    if ($cue['type'] === 'commandResolved') {
      if ($this->executionRefusal !== null) { $this->context->ui->alert($this->executionRefusal); }
      elseif ($this->context->ui->usesGraphicalField() && $this->playback->plan->cinematicStage !== null
        && !$this->playback->plan->usesArenaImpact && $this->playback->getFallbackHitFrames() === []) {
        $this->resultsPending = true;
      } else {
        $authoredImpact = $this->playback->plan->usesArenaImpact || $this->playback->getFallbackHitFrames() !== [];
        $this->showResults($authoredImpact ? $cue['frame'] : null);
      }
      return;
    }
    if ($cue['type'] === 'commandArenaRestored') {
      if ($this->hidControls) { $this->context->ui->showControls(); $this->hidControls = false; }
      return;
    }
    if ($cue['type'] === 'commandAnnouncementEnd') {
      if ($this->announcementVisible) { $this->context->ui->hideMessage(); }
      $this->announcementVisible = false;
      return;
    }
    if ($cue['type'] !== 'commandPhase') {
      // An authored message replaces the callout and owns its own lifetime.
      if (strtolower($cue['type']) === 'showmessage'
        && trim(strval($cue['payload']['text'] ?? $cue['payload']['message'] ?? '')) !== '') {
        $this->announcementVisible = false;
      }
      $data = $cue['payload'] ?? [];
      if ($this->playback->getFallbackHitFrames() !== [] && $cue['frame'] >= $this->playback->plan->impactFrame
        && in_array(strtolower($cue['type']), ['flash', 'shake'], true)
        && ($data['anchor'] ?? null) === 'target' && ($data['scope'] ?? null) === 'target') {
        return; // The individually timed target segments own these graphical effects.
      }
      ($this->presentCue)($cue);
      return;
    }
    switch ($cue['payload']['phase']) {
      case 'source':
        if ($this->playback->plan->summon === null && !$this->playback->plan->hasCinematicStages()) {
          $this->context->ui->hideMessage();
        }
        if ($this->playback->plan->hasCinematicStage('source') && $this->context->ui->usesGraphicalField()) {
          $this->hidControls = true;
          $this->context->ui->hideControls();
        }
        break;
      case 'target':
        if (!$this->playback->plan->hasCinematicStage('target')) {
          if ($this->hidControls && $this->playback->plan->hasCinematicStage('source')) {
            $this->context->ui->showControls();
            $this->hidControls = false;
          }
          break;
        }
      case 'summon-in':
        if ($this->context->ui->usesGraphicalField() && !$this->hidControls) {
          $this->hidControls = true;
          $this->context->ui->hideControls();
        }
        break;
      case 'announce':
        $this->announcementVisible = true;
        $this->context->ui->showMessage($this->announcement);
        break;
      case 'reaction':
        if ($this->resultsPending) { $this->showResults($cue['frame']); }
        break;
      case 'return':
        if ($this->hidControls) { $this->context->ui->showControls(); $this->hidControls = false; }
        $this->context->ui->fieldWindow->clearStatChangePopups();
        $this->context->ui->fieldWindow->clearMagicCastEffects();
        break;
      case 'finish':
        $this->context->ui->hideMessage();
        break;
    }
    ($this->presentCue)($cue);
  }

  private function showResults(?int $startFrame = null): void
  {
    if ($this->resultsPresented) { return; }
    $this->resultsPresented = true;
    $this->resultsPending = false;
    $this->context->ui->characterStatusWindow->setCharacters($this->context->partyRoster->battlers);
    $targets = $this->playback->targets;
    $actor = $this->playback->actor;
    $actorId = CombatResolver::identity($actor);
    $actorResult = array_find($this->commandResult?->targets ?? [],
      static fn(CombatTargetResult $result): bool => $result->targetId === $actorId);
    if (!in_array($actor, $targets, true) && ($actorResult?->resourceChange?->hasChanges
      || $actor->stats->currentHp !== $this->previousVitals[spl_object_id($actor)][0])) {
      $targets[] = $actor;
    }
    $frames = $this->playback->getFallbackHitFrames();
    if ($frames !== []) {
      $frame = $startFrame ?? $this->playback->session->currentFrame;
      $this->scheduledResults = array_map(static fn(CharacterInterface $target): array =>
        ['target' => $target, 'frame' => $frames[spl_object_id($target)] ?? $frame, 'clear' => false], $targets);
      usort($this->scheduledResults, static fn(array $a, array $b): int => $a['frame'] <=> $b['frame']);
      $this->scheduledResults[0]['clear'] = true;
      $this->showScheduledResults($frame);
      return;
    }
    foreach ($targets as $index => $target) {
      $this->showResult($target, $index === 0, $startFrame);
    }
  }

  private function showScheduledResults(int $frame): void
  {
    if ($this->playback->isCancelled) { return; }
    while ($this->scheduledResults !== [] && $this->scheduledResults[0]['frame'] <= $frame) {
      $result = array_shift($this->scheduledResults);
      $this->showResult($result['target'], $result['clear'], $result['frame']);
    }
  }

  private function showResult(CharacterInterface $target, bool $clear, ?int $startFrame): void
  {
    $duration = max(0, ($this->playback->plan->phases['return']['start'] - $this->playback->session->currentFrame)
      / BattleCommandTimeline::FPS);
    [$hp, $mp] = $this->previousVitals[spl_object_id($target)];
    $identity = CombatResolver::identity($target);
    $result = array_find($this->commandResult?->targets ?? [],
      static fn(CombatTargetResult $result): bool => $result->targetId === $identity);
    $lost = $result?->actualHpLost() ?? max(0, $hp - $target->stats->currentHp);
    $restored = $result?->actualHpRestored() ?? max(0, $target->stats->currentHp - $hp);
    $role = match (true) {
      $target->isKnockedOut => BattlePoseRole::KNOCKOUT,
      $lost > 0 => BattlePoseRole::DAMAGE,
      $restored > 0 => BattlePoseRole::HEAL,
      ($result?->resourceChange?->mpLost ?? max(0, $mp - $target->stats->currentMp)) > 0 => BattlePoseRole::DAMAGE,
      ($result?->resourceChange?->mpRestored ?? max(0, $target->stats->currentMp - $mp)) > 0 => BattlePoseRole::HEAL,
      default => BattlePoseRole::getRestingRole($target),
    };
    $this->playback->setReaction($target, $role, $startFrame);
    if ($target instanceof \Ichiloto\Engine\Entities\Enemies\Enemy && $hp > 0 && $target->isKnockedOut) {
      $this->playback->beginEnemyDefeat($target);
    }
    try {
      if (!$this->action instanceof \Ichiloto\Engine\Battle\Actions\GuardAction) {
        $this->context->ui->fieldWindow->showStatChangePopup($target,
          ($this->formatResult)($target, $hp, $mp, $result), clearExisting: $clear, durationSeconds: $duration);
      }
      ($this->presentCue)(['type' => 'commandResult', 'frame' => $startFrame ?? $this->playback->session->currentFrame,
        'payload' => ['target' => $target, 'previousHp' => $hp, 'result' => $result]]);
    } catch (\Throwable $error) { $this->playback->recordPresentationFailure($error); }
  }
}
