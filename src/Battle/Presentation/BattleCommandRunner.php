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
  ) {
    $this->formatResult = Closure::fromCallable($formatResult);
    $this->presentCue = Closure::fromCallable($presentCue);
    foreach ([$actor, ...$targets] as $battler) {
      $this->previousVitals[spl_object_id($battler)] = [$battler->stats->currentHp, $battler->stats->currentMp];
    }
    $this->playback = new BattleCommandPlayback(new BattleCommandTimeline($timings, $source, $target, $terminalTarget, $resultsOnly),
      $actor, $targets, $resultsOnly ? BattlePoseRole::getRestingRole($actor) : BattlePoseRole::getForAction($action),
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
  }

  public function begin(): void
  {
    if ($this->begun || $this->disposed) { return; }
    $this->begun = true;
    if (($refusal = $this->getExecutionRefusal()) !== null) {
      $this->beginRefusal($refusal);
      return;
    }
    $this->context->ui->fieldWindow->setCommandPlayback($this->playback);
    $this->playback->begin();
  }

  public function update(float $seconds): void
  {
    if ($this->disposed) { return; }
    $this->playback->update($seconds);
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
    if ($cue['type'] === 'enemyDefeated') {
      if ($this->playback->defeatStyle->audio) {
        $this->context->game->audioManager->playSystemSound(\Ichiloto\Engine\Audio\Enumerations\SystemSound::ENEMY_COLLAPSE);
      }
      return;
    }
    if ($cue['type'] === 'commandResolved') {
      if ($this->executionRefusal !== null) { $this->context->ui->alert($this->executionRefusal); }
      elseif ($this->context->ui->usesGraphicalField() && $this->playback->plan->cinematicStage !== null) {
        $this->resultsPending = true;
      } else { $this->showResults(); }
      return;
    }
    if ($cue['type'] !== 'commandPhase') {
      ($this->presentCue)($cue);
      return;
    }
    switch ($cue['payload']['phase']) {
      case 'target':
        if ($this->playback->plan->cinematicStage === null) { break; }
      case 'summon-in':
        if ($this->context->ui->usesGraphicalField()) {
          $this->hidControls = true;
          $this->context->ui->hideControls();
          $this->context->ui->hideMessage();
        }
        break;
      case 'announce':
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
    $duration = max(0, ($this->playback->plan->phases['return']['start'] - $this->playback->session->currentFrame)
      / BattleCommandTimeline::FPS);
    $targets = $this->playback->targets;
    $actor = $this->playback->actor;
    $actorId = CombatResolver::identity($actor);
    $actorResult = array_find($this->commandResult?->targets ?? [],
      static fn(CombatTargetResult $result): bool => $result->targetId === $actorId);
    if (!in_array($actor, $targets, true) && ($actorResult?->resourceChange?->hasChanges
      || $actor->stats->currentHp !== $this->previousVitals[spl_object_id($actor)][0])) {
      $targets[] = $actor;
    }
    foreach ($targets as $index => $target) {
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
            ($this->formatResult)($target, $hp, $mp, $result), clearExisting: $index === 0, durationSeconds: $duration);
        }
        ($this->presentCue)(['type' => 'commandResult', 'frame' => $this->playback->session->currentFrame,
          'payload' => ['target' => $target, 'previousHp' => $hp, 'result' => $result]]);
      } catch (\Throwable $error) { $this->playback->recordPresentationFailure($error); }
    }
  }
}
