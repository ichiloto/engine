<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States;

use Ichiloto\Engine\Animations\Animation;
use Ichiloto\Engine\Animations\ActionAnimationResolver;
use Ichiloto\Engine\Core\Timers;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Animations\Timelines\LegacyAnimationTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Battle\Presentation\BattleCommandRunner;
use Ichiloto\Engine\Animations\AnimationLibrary;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Actions\ExecutionEligibility;
use Ichiloto\Engine\Cutscenes\Summons\SummonCompiledCutscene;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\Actions\ItemBattleAction;
use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\CounterAttack;
use Ichiloto\Engine\Battle\CounterAttackResolver;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\TurnBasedEngine;
use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Battle\BattleTargetPolicy;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\TurnExecutionContext;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Turn;
use Ichiloto\Engine\Battle\Presentation\BattleFeedbackRole;
use Ichiloto\Engine\Battle\Resolution\CombatTargetResult;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Battle\Resolution\ElementalOutcome;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDrainSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\MPDamageSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\MPDrainSkillEffect;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Magic\MagicEffectType;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\Skill;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Localization\Vocabulary;
use Ichiloto\Engine\UI\Accessibility;
use Ichiloto\Engine\Util\Debug;

class ActionExecutionState extends TurnState
{
  private bool $endingTurn = false;
  private ?BattleCommandRunner $command = null;
  /** @var list<CounterAttack> Owned by the original command, not the round's turn queue. */
  private array $counterQueue = [];
  private bool $executingCounter = false;
  private ?CounterAttackResolver $counterAttacks;

  public function __construct(TurnBasedEngine $engine, ?CounterAttackResolver $counterAttacks = null)
  {
    parent::__construct($engine);
    $this->counterAttacks = $counterAttacks;
  }

  /**
   * @inheritDoc
   */
  public function enter(TurnStateExecutionContext $context): void
  {
    $this->disposeCommand($context);
    $this->endingTurn = false;
    $this->counterQueue = [];
    $this->executingCounter = false;
    $this->getCounterAttackResolver()->validateBattlers([
      ...$context->partyRoster->battlers, ...$context->troop->members->toArray()]);
    $context->resetTurnCursor();
    $context->ui->commandWindow->blur();
    $context->ui->characterNameWindow->setActiveSelection(-1);
    $context->ui->commandContextWindow->clear();
    $context->ui->fieldWindow->clearTargetIndicators();
    $context->ui->fieldWindow->clearMagicCastEffects();
    $context->ui->fieldWindow->clearStatChangePopups();
    $context->ui->hideMessage();
    $context->ui->refresh();
  }

  /**
   * @inheritDoc
   */
  public function update(TurnStateExecutionContext $context): void
  {
    try {
      Console::updateFrame(fn() => $this->updateTurn($context));
      if ($this->command?->playback->isCompleted) {
        if (!$this->endingTurn && !$this->executingCounter && !$this->command->playback->isCancelled) {
          $this->counterQueue = $this->getCounterAttackResolver()->resolveResponses(
            $this->command->result, $this->command->playback->actor, $this->command->playback->targets,
            $context->partyRoster->battlers, $context->troop->members->toArray());
        }
        if ($this->command->playback->isCancelled) { $this->counterQueue = []; }
        $this->disposeCommand($context);
        Console::updateFrame(function () use ($context): void {
          if ($this->endingTurn) { $this->finishTurn($context); }
          elseif (!$this->beginNextCounter($context) && ($turn = $context->getCurrentTurn()) !== null) {
            $this->beginTurnEnd($context, $turn);
          }
        });
      }
    }
    catch (\Throwable $error) {
      // Retire the command after frame rollback, so rollback cannot resurrect its effects.
      $this->disposeCommand($context);
      $this->counterQueue = [];
      $this->executingCounter = false;
      throw $error;
    }
  }

  private function updateTurn(TurnStateExecutionContext $context): void
  {
    if ($this->command !== null) {
      $this->command->update(max(0, Time::getDeltaTime()));
      $this->refreshCommandField($context);
      return;
    }
    $turn = $context->getCurrentTurn();

    if ($turn === null || $this->battleHasConcluded($context)) {
      $this->setState($this->engine->turnResolutionState);
      return;
    }

    if ($turn->battler->isKnockedOut) {
      $context->advanceTurn();
      $this->transitionToResolutionIfNeeded($context);
      return;
    }

    // A guard raised last round protects until this battler acts again.
    if (method_exists($turn->battler, 'stopGuarding')) {
      $turn->battler->stopGuarding();
    }

    // A state like sleep or paralysis consumes the turn outright.
    if (method_exists($turn->battler, 'getActionBlockingState')
      && ($blockingState = $turn->battler->getActionBlockingState()) !== null
    ) {
      $context->ui->alert(sprintf('%s is down with %s and cannot act!', $turn->battler->name, $blockingState->name));
      $this->beginTurnEnd($context, $turn);
      return;
    }

    if ($turn->action instanceof SkillBattleAction
      && BattleCommandCatalog::isSummonActionId($turn->action->skill->name)
      && (! $turn->battler instanceof Character
        || ! BattleCommandCatalog::canUseSummonAction(
          $turn->battler,
          $context->party,
          $turn->action->skill->name,
          $context->getGameState(),
        ))
    ) {
      $context->ui->alert('That summon is no longer available to this character.');
      $this->beginTurnEnd($context, $turn);
      return;
    }

    $party = $context->partyRoster->battlers;
    $troop = $context->troop->members->toArray();
    $isPartyActor = in_array($turn->battler, $party, true);
    $targets = BattleTargetPolicy::resolveTargets(
      $turn->action?->targetScope ?? new \Ichiloto\Engine\Entities\ItemScope(),
      $turn->battler,
      $isPartyActor ? $party : $troop,
      $isPartyActor ? $troop : $party,
      $turn->targets,
      $this->engine->random,
    );

    if (empty($targets)) {
      $this->beginTurnEnd($context, $turn);
      return;
    }

    $turn->targets = $targets;

    $actionName = $turn->action?->name ?? 'Attack';
    $this->performTurnSequence(
      $context,
      $turn->battler,
      $targets,
      $turn->action,
      $actionName,
      function () use ($turn) {
        $turn->execute(new TurnExecutionContext(
          $this->engine,
          $this->engine->battleConfig,
        ));
      }
    );

  }

  public function exit(TurnStateExecutionContext $context): void
  {
    $this->disposeCommand($context);
    $this->counterQueue = [];
    $this->executingCounter = false;
  }

  private function getCounterAttackResolver(): CounterAttackResolver
  {
    return $this->counterAttacks ??= new CounterAttackResolver(random: $this->engine->random);
  }

  private function beginNextCounter(TurnStateExecutionContext $context): bool
  {
    $this->executingCounter = false;
    while (($response = array_shift($this->counterQueue)) !== null) {
      if (!$this->getCounterAttackResolver()->canExecute($response,
        $context->partyRoster->battlers, $context->troop->members->toArray())) { continue; }
      $this->executingCounter = true;
      $this->performTurnSequence($context, $response->actor, [$response->target], $response->action,
        $response->action->name, function () use ($context, $response): void {
          if ($this->getCounterAttackResolver()->canExecute($response,
            $context->partyRoster->battlers, $context->troop->members->toArray())) {
            $response->action->execute($response->actor, [$response->target]);
          }
        }, sprintf('%s counters with %s!', $response->actor->name, $response->action->name));
      return true;
    }
    return false;
  }

  /** A separate result beat prevents turn-end KO from being hidden by battle completion. */
  protected function beginTurnEnd(TurnStateExecutionContext $context, Turn $turn): void
  {
    $this->endingTurn = true;
    $battler = $turn->battler;
    if (!method_exists($battler, 'tickStates') || $battler->states === [] || $battler->isKnockedOut) {
      $turn->resolveEndStateTicks();
      $this->finishTurn($context);
      return;
    }
    $events = [];
    $this->command = new BattleCommandRunner($context, $battler, [$battler], null, '',
      $context->ui->getPacing()->getTurnTimings(null), null, null,
      function () use ($turn, &$events): void { $events = $turn->resolveEndStateTicks(); },
      function () use (&$events, $battler): array {
        $lines = $this->buildStateTickPopupLines($events);
        if ($battler->isKnockedOut) { $lines[] = ['text' => 'KO', 'color' => Color::LIGHT_RED, 'role' => BattleFeedbackRole::KO]; }
        return $lines;
      },
      function (array $cue) use ($context, $battler, &$events): void {
        if ($cue['type'] !== 'commandResult') { return; }
        $this->playDamageFeedbackSound($context, $battler, $cue['payload']['previousHp']);
        $expired = array_filter($events, static fn(array $event): bool => $event['expired']);
        if ($expired !== []) {
          $context->ui->showMessage(implode(' ', array_map(static fn(array $event): string =>
            sprintf('%s recovered from %s.', $battler->name, $event['state']->name), $expired)));
        }
      }, resultsOnly: true);
    $this->command->begin();
    $this->refreshCommandField($context);
  }

  protected function finishTurn(TurnStateExecutionContext $context): void
  {
    if (($turn = $context->getCurrentTurn()) !== null) { $this->engine->recordTurnCompletion($context, $turn); }
    $this->endingTurn = false;
    $context->advanceTurn();
    $this->transitionToResolutionIfNeeded($context);
  }

  /** @param array<int, array{state: object, hpDelta: int, expired: bool}> $events */
  protected function buildStateTickPopupLines(array $events): array
  {
    return array_values(array_map(static fn(array $event): array => [
      'text' => sprintf('%+d %s', $event['hpDelta'], $event['state']->name),
      'color' => $event['hpDelta'] < 0 ? Color::LIGHT_RED : Color::LIGHT_GREEN,
    ], array_filter($events, static fn(array $event): bool => $event['hpDelta'] !== 0)));
  }

  private function disposeCommand(TurnStateExecutionContext $context): void
  {
    $command = $this->command;
    $this->command = null;
    if ($command === null) { return; }
    $command->dispose();
    try { $context->ui->characterNameWindow->setActiveSelection(-1); }
    catch (\Throwable $error) { Debug::warn('Battle command selection cleanup failed: ' . $error->getMessage()); }
  }

  private function refreshCommandField(TurnStateExecutionContext $context): void
  {
    try { $context->ui->refreshField(); }
    catch (\Throwable $error) {
      $this->command?->playback->recordPresentationFailure($error);
      Debug::warn('Battle command redraw failed: ' . $error->getMessage());
    }
  }

  /**
   * Transitions to turn resolution when the round is exhausted or battle end is already known.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return void
   */
  protected function transitionToResolutionIfNeeded(TurnStateExecutionContext $context): void
  {
    if ($context->getCurrentTurn() === null || $this->battleHasConcluded($context)) {
      $this->setState($this->engine->turnResolutionState);
    }
  }

  /**
   * Determines whether one side has already been wiped out.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return bool
   */
  protected function battleHasConcluded(TurnStateExecutionContext $context): bool
  {
    return empty($context->getLivingPartyBattlers()) || empty($context->getLivingTroopBattlers());
  }

  /**
   * Starts a staged command. Subsequent updates advance its shared playhead.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param CharacterInterface $actor The acting battler.
   * @param CharacterInterface $target The action target.
   * @param BattleAction|null $action The action being resolved.
   * @param string $actionName The action name.
   * @param callable $resolveAction The action resolution callback.
   * @return void
   */
  protected function performTurnSequence(
    TurnStateExecutionContext $context,
    CharacterInterface $actor,
    array $targets,
    ?BattleAction $action,
    string $actionName,
    callable $resolveAction,
    ?string $announcementOverride = null,
  ): void
  {
    $timings = $context->ui->getPacing()->getTurnTimings($action);
    if ($action instanceof ExecutionEligibility && $action->getExecutionRefusal($actor) !== null) {
      $this->command = new BattleCommandRunner($context, $actor, $targets, $action, '',
        $timings, null, null, $resolveAction, $this->buildStatChangePopupLines(...), static function (): void {});
      $this->command->begin();
      return;
    }
    $focusTarget = $targets[0];

    $this->highlightActor($context, $actor);
    $this->highlightTarget($context, $focusTarget);
    $summonCutscene = $action !== null ? $this->resolveSummonCutscene($action) : null;
    $announcement = $announcementOverride ?? ($summonCutscene instanceof SummonCompiledCutscene
      ? (trim(strval($summonCutscene->defaults['moveName'] ?? '')) ?: $actionName)
      : sprintf("%s uses %s!", $actor->name, $actionName));
    $actionAnimation = $summonCutscene instanceof SummonCompiledCutscene
      ? null
      : $this->resolveActionAnimation($action, $actor);
    $legacyTarget = !($actionAnimation?->hasLegacyPresentation ?? false) ? null
      : LegacyAnimationTimeline::compile($actionAnimation,
        \Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline::FPS,
        secondsPerFrame: max(.01, $timings->effectAnimation) / max(1, $actionAnimation->maxFrames));
    $targetEffect = $summonCutscene ?? $legacyTarget;
    $terminalTarget = $legacyTarget;
    $sourceEffect = null;
    if ($actionAnimation !== null) {
      $library = $context->getEffectTimelineLibrary();
      foreach (['source' => $actionAnimation->sourceEffect, 'target' => $actionAnimation->targetEffect] as $stage => $id) {
        if ($id === null) { continue; }
        try {
          $presentation = $context->ui->usesGraphicalField() ? EffectPresentation::GRAPHICAL : EffectPresentation::TERMINAL;
          $effect = $library->load($id, true, $presentation);
          if ($effect->playbackSegments === [] && $effect->cueSchedule === []) { continue; }
          if ($stage === 'source') { $sourceEffect = $effect; }
          else {
            $targetEffect = $effect;
            if ($presentation === EffectPresentation::GRAPHICAL && !$effect->hasTerminalContent) {
              try {
                $terminal = $library->load($id, true, EffectPresentation::TERMINAL);
                if ($terminal->hasTerminalContent) { $terminalTarget = $terminal; }
              } catch (\Throwable $error) {
                Debug::warn('Battle terminal effect could not be loaded; retaining legacy terminal presentation: '
                  . $id . ': ' . $error->getMessage());
              }
            }
          }
        } catch (\Throwable $error) {
          Debug::warn('Battle effect could not be loaded; retaining legacy presentation: ' . $id . ': ' . $error->getMessage());
        }
      }
    }
    $presentationSound = $this->resolveActionPresentationSound($action,
      $actionAnimation?->targetEffect === null ? $actionAnimation : null,
      $summonCutscene instanceof SummonCompiledCutscene, array_filter([$sourceEffect, $targetEffect]));
    $this->command = new BattleCommandRunner($context, $actor, $targets, $action, $announcement,
      $timings, $sourceEffect, $targetEffect, $resolveAction, $this->buildStatChangePopupLines(...),
      function (array $cue) use ($context, $focusTarget, $presentationSound): void {
        if ($cue['type'] === 'commandPhase' && $cue['payload']['phase'] === 'source'
          && $presentationSound instanceof SystemSound) {
          $context->game->audioManager->playSystemSound($presentationSound);
        } elseif ($cue['type'] === 'commandResult') {
          $this->playDamageFeedbackSound($context, $cue['payload']['target'],
            $cue['payload']['previousHp'], $cue['payload']['result']);
        } elseif ($cue['type'] !== 'commandPhase') {
          $this->handleSummonCue($context, $focusTarget, $cue, $cue['frame'], Accessibility::prefersReducedMotion());
        }
      }, terminalTarget: $targetEffect !== $terminalTarget ? $terminalTarget : null);
    $this->command->begin();
  }

  /**
   * Plays the system sound matching the damage the target just took.
   *
   * Fired alongside the damage popup so sight and sound land together. Heals
   * and misses stay silent — their feedback is already visual, and positive
   * outcomes have their own cues elsewhere.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param CharacterInterface $target The action target.
   * @param int $previousHp The target's HP before the action resolved.
   * @param CombatTargetResult|null $result The typed result for this target, when the action resolves HP.
   * @return void
   */
  protected function playDamageFeedbackSound(
    TurnStateExecutionContext $context,
    CharacterInterface $target,
    int $previousHp,
    ?CombatTargetResult $result = null,
  ): void
  {
    $actualHpLost = $result?->actualHpLost() ?? max(0, $previousHp - $target->stats->currentHp);

    if ($actualHpLost < 1) {
      return;
    }

    $sound = match (true) {
      ! $target instanceof Enemy => SystemSound::ACTOR_DAMAGE,
      default => SystemSound::ENEMY_DAMAGE,
    };

    $context->game->audioManager->playSystemSound($sound);
  }

  /**
   * Shows the action announcement, including the caster-side magic effect when applicable.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param CharacterInterface $actor The acting battler.
   * @param BattleAction|null $action The resolved battle action.
   * @param string $message The announcement text.
   * @param float $delaySeconds The phase duration.
   * @return void
   */
  protected function displayAnnouncementPhase(
    TurnStateExecutionContext $context,
    string $message,
    float $delaySeconds
  ): void
  {
    $context->ui->showMessage($message);
    $this->pause($delaySeconds);
  }

  /**
   * Highlights the acting battler in the party name window when applicable.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param CharacterInterface $actor The acting battler.
   * @return void
   */
  protected function highlightActor(TurnStateExecutionContext $context, CharacterInterface $actor): void
  {
    $partyBattlers = $context->partyRoster->battlers;
    $actorIndex = array_search($actor, $partyBattlers, true);
    $context->ui->characterNameWindow->setActiveSelection(is_int($actorIndex) ? $actorIndex : -1);
  }

  /**
   * Highlights the current action target on the battlefield.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param CharacterInterface $target The action target.
   * @return void
   */
  protected function highlightTarget(TurnStateExecutionContext $context, CharacterInterface $target): void
  {
    $context->ui->fieldWindow->clearTargetIndicators();

    if ($target instanceof Character) {
      $partyBattlers = $context->partyRoster->battlers;
      $targetIndex = array_search($target, $partyBattlers, true);

      if (is_int($targetIndex)) {
        $context->ui->fieldWindow->focusPartyBattler($targetIndex);
      }
    }

    if ($target instanceof Enemy) {
      $troopMembers = $context->troop->members->toArray();
      $targetIndex = array_search($target, $troopMembers, true);

      if (is_int($targetIndex)) {
        $context->ui->fieldWindow->focusOnTroopBattler($targetIndex);
      }
    }

    $context->ui->refreshField();
  }

  /**
   * Steps the acting battler forward.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param CharacterInterface $actor The acting battler.
   * @return void
   */
  protected function stepActorForward(TurnStateExecutionContext $context, CharacterInterface $actor): void
  {
    if ($actor instanceof Character) {
      $partyBattlers = $context->partyRoster->battlers;
      $actorIndex = array_search($actor, $partyBattlers, true);

      if (is_int($actorIndex)) {
        $context->ui->fieldWindow->stepPartyBattlerForward($actor, $actorIndex);
      }

      return;
    }

    if ($actor instanceof Enemy) {
      $context->ui->fieldWindow->stepTroopBattlerForward($actor);
    }
  }

  /**
   * Returns the acting battler to its idle position.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param CharacterInterface $actor The acting battler.
   * @return void
   */
  protected function stepActorBack(TurnStateExecutionContext $context, CharacterInterface $actor): void
  {
    if ($actor instanceof Character) {
      $partyBattlers = $context->partyRoster->battlers;
      $actorIndex = array_search($actor, $partyBattlers, true);

      if (is_int($actorIndex)) {
        $context->ui->fieldWindow->stepPartyBattlerBack($actor, $actorIndex);
      }

      return;
    }

    if ($actor instanceof Enemy) {
      $context->ui->fieldWindow->stepTroopBattlerBack($actor);
    }
  }

  /**
   * Shows a message in the info panel and waits for the specified duration.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param string $message The message to display.
   * @param float $delaySeconds The time to wait in seconds.
   * @param bool $hideAfter Whether to hide the info panel afterwards.
   * @return void
   */
  protected function displayPhase(
    TurnStateExecutionContext $context,
    string $message,
    float $delaySeconds,
    bool $hideAfter = false
  ): void
  {
    $context->ui->showMessage($message);
    $this->pause($delaySeconds);

    if ($hideAfter) {
      $context->ui->hideMessage();
    }
  }

  /**
   * Resolves the project-configured action cue for a battle presentation.
   *
   * An animation's own sound cue is more specific and therefore wins. Summon
   * timelines also own their complete audiovisual presentation. Games that
   * omit the returned system-sound keys retain the historical silent action
   * phase while damage feedback continues independently at impact time.
   *
   * @param BattleAction|null $action The action being presented.
   * @param Animation|null $animation The resolved ordinary action animation.
   * @param bool $isSummonAction Whether the action uses a summon timeline.
   * @param array<\Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline> $effects Loaded effects whose authored audio takes priority.
   * @return SystemSound|null The generic action cue, or null when presentation owns it.
   */
  protected function resolveActionPresentationSound(
    ?BattleAction $action,
    ?Animation $animation = null,
    bool $isSummonAction = false,
    array $effects = [],
  ): ?SystemSound
  {
    $authoredSound = array_any($effects, static fn($effect): bool => array_any($effect->cueSchedule,
      static fn(array $cue): bool => in_array(strtolower($cue['type']), ['playsound', 'sound'], true)
        && trim(strval($cue['payload']['soundEffect'] ?? $cue['payload']['sound'] ?? $cue['payload']['assetId'] ?? '')) !== ''));
    if ($action === null || $isSummonAction || $authoredSound || $this->animationHasSoundCue($animation)) {
      return null;
    }

    if ($action instanceof AttackAction) {
      return SystemSound::BATTLE_ATTACK;
    }

    if (! $action instanceof SkillBattleAction) {
      return null;
    }

    if (strtolower(trim($action->skill->name)) === 'attack') {
      return SystemSound::BATTLE_ATTACK;
    }

    if (! $action->skill instanceof MagicSkill) {
      return $this->skillHasDamageEffect($action->skill)
        ? SystemSound::BATTLE_SKILL
        : null;
    }

    return match ($action->skill->effectType) {
      MagicEffectType::DESTRUCTIVE,
      MagicEffectType::DEBUFF => SystemSound::BATTLE_MAGIC_DESTRUCTIVE,
      MagicEffectType::RESTORATIVE,
      MagicEffectType::BUFF => SystemSound::BATTLE_MAGIC_SUPPORT,
    };
  }

  /** Returns whether a non-magical skill applies direct HP or MP damage. */
  protected function skillHasDamageEffect(Skill $skill): bool
  {
    foreach ($skill->effects as $effect) {
      if ($effect instanceof HPDamageSkillEffect
        || $effect instanceof HPDrainSkillEffect
        || $effect instanceof MPDamageSkillEffect
        || $effect instanceof MPDrainSkillEffect
      ) {
        return true;
      }
    }

    return false;
  }

  /** Returns whether an animation already authors at least one sound cue. */
  protected function animationHasSoundCue(?Animation $animation): bool
  {
    if (! $animation instanceof Animation) {
      return false;
    }

    for ($frameIndex = 1; $frameIndex <= $animation->maxFrames; $frameIndex++) {
      if (trim($animation->getCue($frameIndex)?->soundEffect ?? '') !== '') {
        return true;
      }
    }

    return false;
  }

  /**
   * Resolves an authored summon cutscene linked to the current battle action.
   *
   * @param BattleAction|null $action The action being resolved.
   * @return SummonCompiledCutscene|null
   */
  protected function resolveSummonCutscene(?BattleAction $action): ?SummonCompiledCutscene
  {
    if (! $action instanceof SkillBattleAction) {
      return null;
    }

    try {
      $presentation = $this->engine->battleConfig->ui->usesGraphicalField()
        ? EffectPresentation::GRAPHICAL : EffectPresentation::TERMINAL;
      return (BattleCommandCatalog::getBattleSummonLibrary() ?? new SummonCutsceneLibrary())
        ->loadCompiledOrCompileByLinkedActionId($action->skill->name, $presentation);
    } catch (\Throwable $error) {
      Debug::warn('Summon presentation could not be loaded: ' . $error->getMessage());
      return null;
    }
  }

  /** Dispatch authored summon cues independently of the current renderer. */
  protected function handleSummonCue(
    TurnStateExecutionContext $context,
    CharacterInterface $target,
    array $cue,
    int $frame,
    bool $reducedMotion,
  ): void
  {
    $payload = is_array($cue['payload'] ?? null) ? $cue['payload'] : [];
    $type = strtolower(trim(strval($cue['type'] ?? '')));
    // Live command presenters own visual tracks; imperative cues serve legacy blocking previews only.
    if (in_array($type, ['flash', 'shake'], true) && $context->ui->fieldWindow->getCommandPlayback() !== null) {
      return;
    }
    switch ($type) {
      case 'applyeffect':
        // The authored effectTiming gate decides whether this cue resolves combat.
        break;
      case 'playsound':
      case 'sound':
        $path = trim(strval($payload['soundEffect'] ?? $payload['sound'] ?? $payload['assetId'] ?? ''));
        if ($path !== '') { $context->game->audioManager->playSoundEffect($path); }
        break;
      case 'showmessage':
        $message = trim(strval($payload['text'] ?? $payload['message'] ?? ''));
        if ($message !== '') { $context->ui->showMessage($message); }
        break;
      case 'flash':
        if (!$reducedMotion) {
          $color = trim(strval($payload['color'] ?? 'white'));
          $duration = max(1, intval($payload['durationFrames'] ?? $payload['duration'] ?? 1));
          $screen = strtolower(trim(strval($payload['scope'] ?? 'screen'))) !== 'target';
          $context->ui->fieldWindow->beginBattleFlash($target, $screen, $color, $frame, $duration);
        }
        break;
      case 'shake':
        if (!$reducedMotion) {
          $context->ui->fieldWindow->beginSummonShake($frame,
            max(1, intval($payload['durationFrames'] ?? $payload['duration'] ?? 1)),
            max(0, intval($payload['amplitude'] ?? 1)));
        }
        break;
      case 'restorebattlefield':
        $context->ui->fieldWindow->clearBattleFlash();
        $context->ui->fieldWindow->clearSummonShake();
        $context->ui->fieldWindow->clearMagicCastEffects();
        $context->ui->refreshField();
        break;
      default:
        Debug::warn('Unknown summon cue type: ' . $type);
    }
  }

  /**
   * Resolves the editor-authored animation that should play for the action.
   *
   * @param BattleAction|null $action The action being resolved.
   * @return Animation|null
   */
  protected function resolveActionAnimation(?BattleAction $action, CharacterInterface $actor): ?Animation
  {
    $animationLibrary = BattleCommandCatalog::getBattleAnimationLibrary() ?? new AnimationLibrary();

    $animation = ActionAnimationResolver::resolveForAction($action, $actor, $animationLibrary);
    $animationId = ActionAnimationResolver::getExplicitAnimationId($action);
    if ($animation === null && $animationId !== null && BattleCommandCatalog::recordMissingAnimationId($animationId)) {
      Debug::warn(sprintf('Battle animation id %d was not found.', $animationId));
    }
    return $animation;
  }

  /**
   * Resolves the legacy magic effect color for compatibility with battle tests
   * and any remaining effect-driven fallback logic.
   *
   * @param MagicSkill $skill The magic skill being resolved.
   * @return Color
   */
  protected function resolveMagicCastEffectColor(MagicSkill $skill): Color
  {
    return match ($skill->effectType) {
      MagicEffectType::RESTORATIVE => Color::GREEN,
      MagicEffectType::DESTRUCTIVE => Color::RED,
      MagicEffectType::BUFF => Color::BLUE,
      MagicEffectType::DEBUFF => Color::YELLOW,
    };
  }

  /**
   * Shows battlefield popups for every resolved target at once.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param CharacterInterface[] $targets The resolved targets.
   * @param array<int, array{0: int, 1: int}> $previousVitals Pre-action [HP, MP] per target index.
   * @param float $delaySeconds The time to show the popups.
   * @param CombatTargetResult[] $results Ordered typed results matching the targets.
   * @return void
   */
  protected function displayStatChangesForTargets(
    TurnStateExecutionContext $context,
    array $targets,
    array $previousVitals,
    float $delaySeconds,
    array $results = [],
  ): void
  {
    $context->ui->hideMessage();

    foreach ($targets as $index => $target) {
      [$previousHp, $previousMp] = $previousVitals[$index] ?? [$target->stats->currentHp, $target->stats->currentMp];
      $context->ui->fieldWindow->showStatChangePopup(
        $target,
        $this->buildStatChangePopupLines(
          $target,
          $previousHp,
          $previousMp,
          $results[$index] ?? null,
        ),
        clearExisting: $index === 0,
        durationSeconds: $delaySeconds,
      );
    }

    $context->ui->refresh();
    $this->pause($delaySeconds);
    $context->ui->fieldWindow->clearStatChangePopups();
    $context->ui->refreshField();
  }

  /**
   * Shows battlefield popups for the target's resolved HP and MP changes.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param CharacterInterface $target The resolved target.
   * @param int $previousHp The target HP before the action.
   * @param int $previousMp The target MP before the action.
   * @param CombatTargetResult|null $result The typed HP-resolution result for this target.
   * @param float $delaySeconds The time to show the popup.
   * @return void
   */
  protected function displayStatChanges(
    TurnStateExecutionContext $context,
    CharacterInterface $target,
    int $previousHp,
    int $previousMp,
    float $delaySeconds
  ): void
  {
    $context->ui->hideMessage();
    $context->ui->fieldWindow->showStatChangePopup(
      $target,
      $this->buildStatChangePopupLines($target, $previousHp, $previousMp),
      durationSeconds: $delaySeconds,
    );
    $context->ui->refresh();
    $this->pause($delaySeconds);
    $context->ui->fieldWindow->clearStatChangePopups();
    $context->ui->refreshField();
  }

  /**
   * Builds the floating popup lines for the target's resolved stat changes.
   *
   * @param CharacterInterface $target The action target.
   * @param int $previousHp The target HP before the action.
   * @param int $previousMp The target MP before the action.
   * @return list<array{text: string, color: Color, role?: BattleFeedbackRole}> The popup lines to render.
   */
  protected function buildStatChangePopupLines(
    CharacterInterface $target,
    int $previousHp,
    int $previousMp,
    ?CombatTargetResult $result = null,
  ): array
  {
    $mpLost = $result?->resourceChange?->mpLost ?? max(0, $previousMp - $target->stats->currentMp);
    $mpRestored = $result?->resourceChange?->mpRestored ?? max(0, $target->stats->currentMp - $previousMp);
    $lines = [];
    $hpDamage = $result?->getResolvedHpDamage() ?? max(0, $previousHp - $target->stats->currentHp);
    $hpRestored = $result?->actualHpRestored() ?? max(0, $target->stats->currentHp - $previousHp);

    if ($hpDamage > 0) {
      $lines[] = ['text' => strval($hpDamage), 'color' => Color::LIGHT_RED, 'role' => BattleFeedbackRole::DAMAGE];
    }

    if ($hpRestored > 0) {
      $lines[] = ['text' => '+' . $hpRestored, 'color' => Color::LIGHT_GREEN, 'role' => BattleFeedbackRole::HEAL];
    }

    if ($mpLost > 0) {
      $lines[] = ['text' => '-' . $mpLost . ' ' . Vocabulary::getTerm('stats.mp', 'MP'), 'color' => Color::LIGHT_CYAN, 'role' => BattleFeedbackRole::MP_LOSS];
    }
    if ($mpRestored > 0) {
      $lines[] = ['text' => '+' . $mpRestored . ' ' . Vocabulary::getTerm('stats.mp', 'MP'), 'color' => Color::LIGHT_CYAN, 'role' => BattleFeedbackRole::MP_GAIN];
    }

    $critical = $result !== null
      ? array_any($result->hits, static fn($hit): bool => $hit->critical)
      : ($target->lastHitWasCritical ?? false);

    if ($critical) {
      array_unshift($lines, ['text' => 'CRITICAL', 'color' => Color::YELLOW, 'role' => BattleFeedbackRole::CRITICAL]);
      $target->lastHitWasCritical = false;
    }

    // The legacy path stores semantic outcome flags as strings; adapt them here only.
    $reactionOutcome = $result !== null
      ? $this->resolveElementalReaction($result)
      : match ($target->lastElementReaction ?? null) {
        'WEAK!' => ElementalOutcome::WEAK,
        'RESIST' => ElementalOutcome::RESIST,
        'NULL' => ElementalOutcome::NULL,
        'ABSORB' => ElementalOutcome::ABSORB,
        default => null,
      };
    $reaction = $result !== null
      ? match ($reactionOutcome) {
        ElementalOutcome::WEAK => 'WEAK!',
        ElementalOutcome::RESIST => 'RESIST',
        ElementalOutcome::NULL => 'NULL',
        ElementalOutcome::ABSORB => 'ABSORB',
        default => null,
      }
      : ($target->lastElementReaction ?? null);

    if ($reaction !== null) {
      $reactionColor = match ($reaction) {
        'WEAK!' => Color::LIGHT_RED,
        'ABSORB' => Color::LIGHT_GREEN,
        default => Color::LIGHT_CYAN,
      };
      $reactionRole = match ($reactionOutcome) {
        ElementalOutcome::WEAK => BattleFeedbackRole::WEAK,
        ElementalOutcome::RESIST => BattleFeedbackRole::RESIST,
        ElementalOutcome::NULL => BattleFeedbackRole::NULL,
        ElementalOutcome::ABSORB => BattleFeedbackRole::ABSORB,
        default => null,
      };
      $reactionLine = ['text' => $reaction, 'color' => $reactionColor];
      if ($reactionRole !== null) {
        $reactionLine['role'] = $reactionRole;
      }
      array_unshift($lines, $reactionLine);
      $target->lastElementReaction = null;
    }

    if ($target->isKnockedOut) {
      $lines[] = ['text' => 'KO', 'color' => Color::YELLOW, 'role' => BattleFeedbackRole::KO];
    }

    if (empty($lines) && $result === null) {
      $lines[] = ['text' => 'MISS', 'color' => Color::WHITE, 'role' => BattleFeedbackRole::MISS];
    } elseif (empty($lines) && array_any($result->hits, static fn($hit): bool => ! $hit->hit)) {
      $lines[] = ['text' => 'MISS', 'color' => Color::WHITE, 'role' => BattleFeedbackRole::MISS];
    } elseif (empty($lines) && $result->hits !== []) {
      $lines[] = ['text' => '0', 'color' => Color::WHITE, 'role' => BattleFeedbackRole::ZERO];
    }

    return $lines;
  }

  /**
   * Resolves the highest-priority elemental feedback from typed hit results.
   */
  protected function resolveElementalReaction(CombatTargetResult $result): ?ElementalOutcome
  {
    foreach ([
      ElementalOutcome::ABSORB,
      ElementalOutcome::NULL,
      ElementalOutcome::WEAK,
      ElementalOutcome::RESIST,
    ] as $outcome) {
      if (array_any($result->hits, static fn($hit): bool => $hit->elementalOutcome === $outcome)) {
        return $outcome;
      }
    }

    return null;
  }

  /**
   * Waits for the given number of seconds.
   *
   * @param float $seconds The time to wait in seconds.
   * @return void
   */
  protected function pause(float $seconds): void
  {
    Timers::wait($seconds);
  }
}
