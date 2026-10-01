<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States;

use Ichiloto\Engine\Animations\Animation;
use Ichiloto\Engine\Animations\ActionAnimationResolver;
use Ichiloto\Engine\Core\Timers;
use Ichiloto\Engine\Animations\AnimationCue;
use Ichiloto\Engine\Animations\AnimationLibrary;
use Ichiloto\Engine\Animations\AnimationPlayer;
use Ichiloto\Engine\Animations\AnimationTargetPosition;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Cutscenes\Summons\SummonCompiledCutscene;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutscenePlayer;
use Ichiloto\Engine\Cutscenes\Summons\SummonEffectTrigger;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\Actions\ItemBattleAction;
use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\TurnExecutionContext;
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
use Ichiloto\Engine\UI\Accessibility;
use Ichiloto\Engine\Util\Debug;

class ActionExecutionState extends TurnState
{
  /**
   * @inheritDoc
   */
  public function enter(TurnStateExecutionContext $context): void
  {
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
      $context->advanceTurn();
      $this->transitionToResolutionIfNeeded($context);
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
      $context->advanceTurn();
      $this->transitionToResolutionIfNeeded($context);
      return;
    }

    $targets = array_values(array_filter(
      $turn->targets,
      fn(CharacterInterface $target) => ! $target->isKnockedOut
    ));

    if (empty($targets)) {
      $targets = $context->getLivingOpponents($turn->battler);
    }

    if (empty($targets)) {
      $context->advanceTurn();
      $this->transitionToResolutionIfNeeded($context);
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

    if ($this->battleHasConcluded($context)) {
      $this->setState($this->engine->turnResolutionState);
      return;
    }

    $context->advanceTurn();
    $this->transitionToResolutionIfNeeded($context);
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
   * Performs the staged turn sequence for the acting battler.
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
    callable $resolveAction
  ): void
  {
    $timings = $context->ui->getPacing()->getTurnTimings($action);
    $focusTarget = $targets[0];

    $this->highlightActor($context, $actor);
    $this->highlightTarget($context, $focusTarget);
    $this->stepActorForward($context, $actor);
    $this->pause($timings->stepForward);
    $summonCutscene = $action !== null ? $this->resolveSummonCutscene($action) : null;
    $announcement = $summonCutscene instanceof SummonCompiledCutscene
      ? (trim(strval($summonCutscene->defaults['moveName'] ?? '')) ?: $actionName)
      : sprintf("%s uses %s!", $actor->name, $actionName);
    $this->displayAnnouncementPhase($context, $announcement, $timings->announcement);
    $actionAnimation = $summonCutscene instanceof SummonCompiledCutscene
      ? null
      : $this->resolveActionAnimation($action);
    $presentationSound = $this->resolveActionPresentationSound(
      $action,
      $actionAnimation,
      $summonCutscene instanceof SummonCompiledCutscene,
    );

    if ($presentationSound instanceof SystemSound) {
      $context->game->audioManager->playSystemSound($presentationSound);
    }

    $previousVitals = [];
    foreach ($targets as $index => $target) {
      $previousVitals[$index] = [$target->stats->currentHp, $target->stats->currentMp];
    }
    $this->resolvePresentedAction($context, $actor, $focusTarget, $action, $timings,
      $summonCutscene, $actionAnimation, $resolveAction);

    $this->stepActorBack($context, $actor);
    $this->pause($timings->stepBack);

    $context->ui->characterStatusWindow->setCharacters($context->party->battlers->toArray());
    $typedTargetResults = [];
    foreach ($targets as $target) {
      $targetId = CombatResolver::identity($target);
      $typedTargetResults[] = array_find(
        $action?->lastResult?->targets ?? [],
        static fn(CombatTargetResult $result): bool => $result->targetId === $targetId,
      );
    }

    $this->playDamageFeedbackSound(
      $context,
      $focusTarget,
      $previousVitals[0][0],
      $typedTargetResults[0] ?? null,
    );
    $this->displayStatChangesForTargets(
      $context,
      $targets,
      $previousVitals,
      $timings->statChanges,
      $typedTargetResults,
    );
    $this->displayPhase($context, 'Turn over.', $timings->turnOver, hideAfter: true);
    $context->ui->characterNameWindow->setActiveSelection(-1);
    $context->ui->fieldWindow->clearTargetIndicators();
    $context->ui->fieldWindow->clearMagicCastEffects();
    $context->ui->fieldWindow->clearStatChangePopups();
    $context->ui->refreshField();
  }

  /** Resolve gameplay once while the authored presentation advances or fails. */
  protected function resolvePresentedAction(
    TurnStateExecutionContext $context,
    CharacterInterface $actor,
    CharacterInterface $target,
    ?BattleAction $action,
    BattleTurnTimings $timings,
    ?SummonCompiledCutscene $summonCutscene,
    ?Animation $actionAnimation,
    callable $resolveAction,
  ): void
  {
    $resolved = false;
    $resolutionFailure = null;
    $resolveOnce = function () use (&$resolved, &$resolutionFailure, $resolveAction): void {
      if ($resolved) { return; }
      $resolved = true;
      try { $resolveAction(); }
      catch (\Throwable $error) { $resolutionFailure = $error; throw $error; }
    };
    try {
      $extendedAnimationHandled = $this->playActionAnimation(
        $context,
        $actor,
        $target,
        $action,
        $timings->actionAnimation,
        $timings->effectAnimation,
        $summonCutscene,
        $actionAnimation,
        $resolveOnce,
      );
    } catch (\Exception $error) {
      if ($error === $resolutionFailure) { throw $error; }
      Debug::warn('Battle effect presentation failed: ' . $error->getMessage());
      $context->ui->fieldWindow->clearBattleFlash();
      $context->ui->fieldWindow->clearSummonShake();
      $extendedAnimationHandled = true;
    } finally {
      if ($summonCutscene instanceof SummonCompiledCutscene) { $resolveOnce(); }
    }
    if (! $extendedAnimationHandled) {
      $this->pause($timings->actionAnimation);
      $this->pause($timings->effectAnimation);
    }
    $resolveOnce();
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
      $target->stats->currentHp <= 0 => SystemSound::ENEMY_COLLAPSE,
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
    $partyBattlers = $context->party->battlers->toArray();
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
      $partyBattlers = $context->party->battlers->toArray();
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
      $partyBattlers = $context->party->battlers->toArray();
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
      $partyBattlers = $context->party->battlers->toArray();
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
   * Plays the configured action animation over the current target.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param CharacterInterface $actor The acting battler.
   * @param CharacterInterface $target The resolved action target.
   * @param BattleAction|null $action The resolved action.
   * @param float $delaySeconds The time budget for the animation phase.
   * @param float $effectDisplaySeconds The final-frame display phase in reduced motion.
   * @param SummonCompiledCutscene|null $summonCutscene The pre-resolved summon presentation.
   * @param Animation|null $animation The pre-resolved ordinary action animation.
   * @return bool Whether the action animation consumed the phase timing.
   */
  protected function playActionAnimation(
    TurnStateExecutionContext $context,
    CharacterInterface $actor,
    CharacterInterface $target,
    ?BattleAction $action,
    float $delaySeconds,
    float $effectDisplaySeconds,
    ?SummonCompiledCutscene $summonCutscene = null,
    ?Animation $animation = null,
    ?callable $resolveAction = null,
  ): bool
  {
    if ($summonCutscene instanceof SummonCompiledCutscene) {
      $this->playSummonCutscene($context, $actor, $target, $summonCutscene, $resolveAction, $effectDisplaySeconds);
      return true;
    }

    $animation ??= $this->resolveActionAnimation($action);

    if (! $animation instanceof Animation) {
      return false;
    }

    $player = new AnimationPlayer(max(0.01, $delaySeconds / max(1, $animation->maxFrames)));
    $flashEndFrame = 0;
    $player->play($animation, function (int $frameIndex) use ($context, $target, $animation): void {
      $context->ui->fieldWindow->showActionAnimationFrame($target, $animation, $frameIndex);
    }, function (AnimationCue $cue, int $frameIndex) use ($context, $target, $animation, &$flashEndFrame): void {
      if ($cue->soundEffect !== '') {
        try { $context->game->audioManager->playSoundEffect($cue->soundEffect); }
        catch (\Exception $error) { Debug::warn('Animation sound cue failed: ' . $error->getMessage()); }
      }
      if (!Accessibility::prefersReducedMotion() && $cue->flashColor !== null && $cue->flashDurationFrames > 0) {
        $flashEndFrame = max($flashEndFrame, $frameIndex + $cue->flashDurationFrames);
        try {
          $context->ui->fieldWindow->beginBattleFlash($target, $animation->position === AnimationTargetPosition::SCREEN, $cue->flashColor,
            $frameIndex, $cue->flashDurationFrames);
        } catch (\Exception $error) { Debug::warn('Animation flash cue failed: ' . $error->getMessage()); }
      }
    }, Accessibility::prefersReducedMotion());
    for ($frame = $animation->maxFrames + 1; $frame < $flashEndFrame; $frame++) {
      $context->ui->fieldWindow->showActionAnimationFrame($target, $animation, $frame);
      $this->pause($player->secondsPerFrame);
    }
    if (Accessibility::prefersReducedMotion()) {
      $this->pause($effectDisplaySeconds);
    }
    $context->ui->fieldWindow->clearBattleFlash();
    $context->ui->fieldWindow->clearMagicCastEffects();
    $context->ui->refreshField();

    return true;
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
   * @return SystemSound|null The generic action cue, or null when presentation owns it.
   */
  protected function resolveActionPresentationSound(
    ?BattleAction $action,
    ?Animation $animation = null,
    bool $isSummonAction = false,
  ): ?SystemSound
  {
    if ($action === null || $isSummonAction || $this->animationHasSoundCue($animation)) {
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
      return (BattleCommandCatalog::getBattleSummonLibrary() ?? new SummonCutsceneLibrary())
        ->loadCompiledOrCompileByLinkedActionId($action->skill->name);
    } catch (\Throwable $error) {
      Debug::warn('Summon presentation could not be loaded: ' . $error->getMessage());
      return null;
    }
  }

  /**
   * Plays a full-screen summon cutscene over the battlefield.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param SummonCompiledCutscene $cutscene The compiled summon cutscene.
   * @return void
   */
  protected function playSummonCutscene(
    TurnStateExecutionContext $context,
    CharacterInterface $actor,
    CharacterInterface $target,
    SummonCompiledCutscene $cutscene,
    ?callable $resolveAction,
    float $effectDisplaySeconds,
  ): void
  {
    $effect = new SummonEffectTrigger(
      is_array($cutscene->defaults['effectTiming'] ?? null) ? $cutscene->defaults['effectTiming'] : [],
      $resolveAction ?? static function (): void {},
    );
    $reducedMotion = Accessibility::prefersReducedMotion();
    $transitionIn = is_array($cutscene->transitionCache["in"] ?? null)
      ? $cutscene->transitionCache["in"]
      : [];
    $transitionOut = is_array($cutscene->transitionCache["out"] ?? null)
      ? $cutscene->transitionCache["out"]
      : [];

    $failure = null;
    try {
      $context->ui->hideMessage();
      $context->ui->hideControls();
      $context->ui->fieldWindow->clearTargetIndicators();
      $context->ui->fieldWindow->clearMagicCastEffects();
      $context->ui->fieldWindow->clearStatChangePopups();
      if (!$reducedMotion) { $this->playSummonTransition($context, $transitionIn, "in"); }
      $context->ui->fieldWindow->erase();
      $context->ui->fieldWindow->render();
      if (!$reducedMotion) { $this->displaySummonTitleCard($context, $actor, $cutscene); }

      (new SummonCutscenePlayer())->play(
        $cutscene,
        function (int $frameIndex) use ($context, $cutscene): void {
          $context->ui->fieldWindow->showSummonCutsceneFrame($cutscene, $frameIndex);
        },
        function (array $cue, int $frame) use ($context, $target, $effect, $reducedMotion): void {
          $effect->onCue($cue);
          try { $this->handleSummonCue($context, $target, $cue, $frame, $reducedMotion); }
          catch (\Exception $error) { Debug::warn('Summon cue presentation failed: ' . $error->getMessage()); }
        },
        $effect->onFrame(...),
        $reducedMotion,
      );

      $effect->finish();
      if ($reducedMotion) { $this->pause($effectDisplaySeconds); }
      if (!$reducedMotion) { $this->playSummonTransition($context, $transitionOut, "out"); }
    } catch (\Throwable $error) {
      $failure = $error;
    } finally {
      // Gameplay resolution outranks a presentation failure, and cleanup must
      // never replace the original gameplay exception.
      try { $effect->finish(); }
      catch (\Throwable $error) { $failure = $error; }
      foreach ([
        'flash' => fn() => $context->ui->fieldWindow->clearBattleFlash(),
        'shake' => fn() => $context->ui->fieldWindow->clearSummonShake(),
        'effect art' => fn() => $context->ui->fieldWindow->clearMagicCastEffects(),
        'field' => fn() => $context->ui->refreshField(),
        'controls' => fn() => $context->ui->showControls(),
      ] as $part => $clear) {
        try { $clear(); }
        catch (\Throwable $error) {
          Debug::warn(sprintf('Summon %s cleanup failed: %s', $part, $error->getMessage()));
          $failure ??= $error;
        }
      }
    }
    if ($failure !== null) { throw $failure; }
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
   * Displays a short summon title card before playback begins.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param CharacterInterface $actor The acting battler.
   * @param SummonCompiledCutscene $cutscene The compiled summon cutscene.
   * @return void
   */
  protected function displaySummonTitleCard(
    TurnStateExecutionContext $context,
    CharacterInterface $actor,
    SummonCompiledCutscene $cutscene,
  ): void
  {
    $summonName = trim(strval($cutscene->defaults["name"] ?? $cutscene->sourceId));

    if ($summonName === "") {
      return;
    }

    $targetPresentation = is_array($cutscene->defaults["targetPresentation"] ?? null)
      ? $cutscene->defaults["targetPresentation"]
      : [];
    $showCasterNameBanner = boolval($targetPresentation["showCasterNameBanner"] ?? false);

    $context->ui->fieldWindow->showSummonTitleCard(
      $summonName,
      $showCasterNameBanner ? $actor->name : null,
    );
    $this->pause(0.8);
  }

  /**
   * Plays a lightweight summon transition over the battlefield.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param array<string, mixed> $transition The compiled transition data.
   * @param string $direction The transition direction.
   * @return void
   */
  protected function playSummonTransition(
    TurnStateExecutionContext $context,
    array $transition,
    string $direction,
  ): void
  {
    $durationMs = intval($transition["durationMs"] ?? 0);

    if ($durationMs <= 0) {
      return;
    }

    $type = strtolower(trim(strval($transition["type"] ?? "")));

    if ($type === "" || ! in_array($type, ["fadetoblack", "fadefromblack"], true)) {
      $this->pause($durationMs / 1000);
      return;
    }

    $steps = 4;
    $stepDelay = max(0.01, ($durationMs / 1000) / $steps);
    $colorName = isset($transition["color"]) ? strval($transition["color"]) : null;

    for ($stepIndex = 0; $stepIndex < $steps; $stepIndex++) {
      $progress = $steps === 1
        ? 1.0
        : $stepIndex / max(1, $steps - 1);
      $context->ui->fieldWindow->showSummonTransitionFrame($progress, $direction, $colorName);
      $this->pause($stepDelay);
    }

    $context->ui->fieldWindow->clearMagicCastEffects();
  }

  /**
   * Resolves the editor-authored animation that should play for the action.
   *
   * @param BattleAction|null $action The action being resolved.
   * @return Animation|null
   */
  protected function resolveActionAnimation(?BattleAction $action): ?Animation
  {
    $animationLibrary = BattleCommandCatalog::getBattleAnimationLibrary() ?? new AnimationLibrary();

    $animationId = match (true) {
      $action instanceof SkillBattleAction => $action->skill->animationId,
      $action instanceof ItemBattleAction => $action->item->animationId,
      default => null,
    };
    if ($animationId !== null) {
      $animation = $animationLibrary->findById($animationId);
      if ($animation === null && BattleCommandCatalog::recordMissingAnimationId($animationId)) {
        Debug::warn(sprintf('Battle animation id %d was not found.', $animationId));
      }
      return $animation;
    }

    if ($action instanceof ItemBattleAction) { return null; }

    if ($action instanceof SkillBattleAction) {
      foreach (ActionAnimationResolver::getSkillCandidateNames(
        $action->skill->name,
        $action->skill instanceof MagicSkill ? $action->skill->effectType : null,
      ) as $name) {
        $animation = $animationLibrary->findByName($name);
        if ($animation instanceof Animation) { return $animation; }
      }
      return null;
    }

    if ($action instanceof AttackAction) {
      return $animationLibrary->findByName('Hit Spark');
    }

    return null;
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
    $mpDelta = $target->stats->currentMp - $previousMp;
    $lines = [];
    $hpLost = $result?->actualHpLost() ?? max(0, $previousHp - $target->stats->currentHp);
    $hpRestored = $result?->actualHpRestored() ?? max(0, $target->stats->currentHp - $previousHp);

    if ($hpLost > 0) {
      $lines[] = ['text' => strval($hpLost), 'color' => Color::LIGHT_RED, 'role' => BattleFeedbackRole::DAMAGE];
    }

    if ($hpRestored > 0) {
      $lines[] = ['text' => '+' . $hpRestored, 'color' => Color::LIGHT_GREEN, 'role' => BattleFeedbackRole::HEAL];
    }

    if ($mpDelta < 0) {
      $lines[] = ['text' => '-' . abs($mpDelta) . ' MP', 'color' => Color::LIGHT_CYAN, 'role' => BattleFeedbackRole::MP_LOSS];
    } elseif ($mpDelta > 0) {
      $lines[] = ['text' => '+' . $mpDelta . ' MP', 'color' => Color::LIGHT_CYAN, 'role' => BattleFeedbackRole::MP_GAIN];
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
