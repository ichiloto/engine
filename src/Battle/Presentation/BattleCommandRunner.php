<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Closure;
use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnStateExecutionContext;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Battle\Resolution\CombatTargetResult;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\UI\Accessibility;
use Ichiloto\Engine\Util\Debug;

/** Owns one action's presentation lifetime, not its combat rules or the battle clock. */
final class BattleCommandRunner
{
  public readonly BattleCommandPlayback $playback;
  private Closure $formatResult;
  private Closure $presentCue;
  private array $previousVitals = [];
  private bool $reportedFailure = false;
  private bool $hidControls = false;

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
      $resolve, $this->handleCue(...));
  }

  public function begin(): void
  {
    $this->context->ui->fieldWindow->setCommandPlayback($this->playback);
    $this->playback->begin();
  }

  public function update(float $seconds): void
  {
    $this->playback->update($seconds);
    if (!$this->reportedFailure && $this->playback->presentationFailure !== null) {
      Debug::warn('Battle command presentation failed: ' . $this->playback->presentationFailure->getMessage());
      $this->reportedFailure = true;
    }
  }

  public function dispose(): void
  {
    $this->playback->cancel();
    $field = $this->context->ui->fieldWindow;
    if ($field->getCommandPlayback() === $this->playback) {
      $field->setCommandPlayback(null);
      foreach ([$field->clearBattleFlash(...), $field->clearSummonShake(...),
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

  private function handleCue(array $cue): void
  {
    if ($cue['type'] === 'commandResolved') {
      $this->showResults();
      return;
    }
    if ($cue['type'] !== 'commandPhase') {
      ($this->presentCue)($cue);
      return;
    }
    switch ($cue['payload']['phase']) {
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

  private function showResults(): void
  {
    $this->context->ui->characterStatusWindow->setCharacters($this->context->party->battlers->toArray());
    $duration = max(0, ($this->playback->plan->phases['return']['start'] - $this->playback->session->currentFrame)
      / BattleCommandTimeline::FPS);
    $targets = $this->playback->targets;
    $actor = $this->playback->actor;
    if (!in_array($actor, $targets, true) && $actor->stats->currentHp !== $this->previousVitals[spl_object_id($actor)][0]) {
      $targets[] = $actor;
    }
    foreach ($targets as $index => $target) {
      [$hp, $mp] = $this->previousVitals[spl_object_id($target)];
      $identity = CombatResolver::identity($target);
      $result = array_find($this->action?->lastResult?->targets ?? [],
        static fn(CombatTargetResult $result): bool => $result->targetId === $identity);
      $lost = $result?->actualHpLost() ?? max(0, $hp - $target->stats->currentHp);
      $restored = $result?->actualHpRestored() ?? max(0, $target->stats->currentHp - $hp);
      $role = match (true) {
        $target->isKnockedOut => BattlePoseRole::KNOCKOUT,
        $lost > 0 => BattlePoseRole::DAMAGE,
        $restored > 0 => BattlePoseRole::HEAL,
        default => BattlePoseRole::getRestingRole($target),
      };
      $this->playback->setReaction($target, $role);
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
