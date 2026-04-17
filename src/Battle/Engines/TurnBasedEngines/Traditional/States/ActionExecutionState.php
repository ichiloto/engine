<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States;

use Ichiloto\Engine\Animations\Animation;
use Ichiloto\Engine\Animations\AnimationLibrary;
use Ichiloto\Engine\Animations\AnimationPlayer;
use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Cutscenes\Summons\SummonCompiledCutscene;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutscenePlayer;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\TurnExecutionContext;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Magic\MagicEffectType;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\IO\Enumerations\Color;

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

    $target = $targets[0];
    $turn->targets = [$target];

    $actionName = $turn->action?->name ?? 'Attack';
    $this->performTurnSequence(
      $context,
      $turn->battler,
      $target,
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
    CharacterInterface $target,
    ?BattleAction $action,
    string $actionName,
    callable $resolveAction
  ): void
  {
    $timings = $context->ui->getPacing()->getTurnTimings($action);

    $this->highlightActor($context, $actor);
    $this->highlightTarget($context, $target);
    $this->stepActorForward($context, $actor);
    $this->pause($timings->stepForward);
    $this->displayAnnouncementPhase($context, $action !== null && $this->resolveSummonCutscene($action) instanceof SummonCompiledCutscene ? $actionName : sprintf("%s uses %s!", $actor->name, $actionName), $timings->announcement);
    $extendedAnimationHandled = $this->playActionAnimation($context, $actor, $target, $action, $timings->actionAnimation);
    if (! $extendedAnimationHandled) {
      $this->pause($timings->actionAnimation);
      $this->pause($timings->effectAnimation);
    }

    $previousHp = $target->stats->currentHp;
    $previousMp = $target->stats->currentMp;
    $resolveAction();

    $this->stepActorBack($context, $actor);
    $this->pause($timings->stepBack);

    $context->ui->characterStatusWindow->setCharacters($context->party->battlers->toArray());
    $this->displayStatChanges($context, $target, $previousHp, $previousMp, $timings->statChanges);
    $this->displayPhase($context, 'Turn over.', $timings->turnOver, hideAfter: true);
    $context->ui->characterNameWindow->setActiveSelection(-1);
    $context->ui->fieldWindow->clearTargetIndicators();
    $context->ui->fieldWindow->clearMagicCastEffects();
    $context->ui->fieldWindow->clearStatChangePopups();
    $context->ui->refreshField();
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
   * @return bool Whether the action animation consumed the phase timing.
   */
  protected function playActionAnimation(
    TurnStateExecutionContext $context,
    CharacterInterface $actor,
    CharacterInterface $target,
    ?BattleAction $action,
    float $delaySeconds
  ): bool
  {
    $summonCutscene = $this->resolveSummonCutscene($action);

    if ($summonCutscene instanceof SummonCompiledCutscene) {
      $this->playSummonCutscene($context, $actor, $summonCutscene);
      return true;
    }

    $animation = $this->resolveActionAnimation($action);

    if (! $animation instanceof Animation) {
      return false;
    }

    $player = new AnimationPlayer(max(0.01, $delaySeconds / max(1, $animation->maxFrames)));
    $player->play($animation, function (int $frameIndex) use ($context, $target, $animation): void {
      $context->ui->fieldWindow->showActionAnimationFrame($target, $animation, $frameIndex);
    });
    $context->ui->fieldWindow->clearMagicCastEffects();
    $context->ui->refreshField();

    return true;
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
      return (new SummonCutsceneLibrary())->loadCompiledOrCompileByLinkedActionId($action->skill->name);
    } catch (\Throwable) {
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
    SummonCompiledCutscene $cutscene,
  ): void
  {
    $transitionIn = is_array($cutscene->transitionCache["in"] ?? null)
      ? $cutscene->transitionCache["in"]
      : [];
    $transitionOut = is_array($cutscene->transitionCache["out"] ?? null)
      ? $cutscene->transitionCache["out"]
      : [];

    $context->ui->hideMessage();
    $context->ui->hideControls();
    $context->ui->fieldWindow->clearTargetIndicators();
    $context->ui->fieldWindow->clearMagicCastEffects();
    $context->ui->fieldWindow->clearStatChangePopups();

    $this->playSummonTransition($context, $transitionIn, "in");
    $context->ui->fieldWindow->erase();
    $context->ui->fieldWindow->render();
    $this->displaySummonTitleCard($context, $actor, $cutscene);

    (new SummonCutscenePlayer())->play(
      $cutscene,
      function (int $frameIndex) use ($context, $cutscene): void {
        $context->ui->fieldWindow->showSummonCutsceneFrame($cutscene, $frameIndex);
      }
    );

    $context->ui->fieldWindow->clearMagicCastEffects();
    $context->ui->refreshField();
    $this->playSummonTransition($context, $transitionOut, "out");
    $context->ui->fieldWindow->clearMagicCastEffects();
    $context->ui->refreshField();
    $context->ui->showControls();
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
    $animationLibrary = new AnimationLibrary('Data/animations.php');

    if ($action instanceof SkillBattleAction) {
      $explicitAnimation = $animationLibrary->findByName($action->skill->name);

      if ($explicitAnimation instanceof Animation) {
        return $explicitAnimation;
      }

      if ($action->skill instanceof MagicSkill) {
        return match ($action->skill->effectType) {
          MagicEffectType::RESTORATIVE,
          MagicEffectType::BUFF => $animationLibrary->findByName('Healing Aura'),
          MagicEffectType::DESTRUCTIVE,
          MagicEffectType::DEBUFF => $animationLibrary->findByName('Hit Spark'),
        };
      }

      return $animationLibrary->findByName('Hit Spark');
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
   * Shows battlefield popups for the target's resolved HP and MP changes.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param CharacterInterface $target The resolved target.
   * @param int $previousHp The target HP before the action.
   * @param int $previousMp The target MP before the action.
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
      $this->buildStatChangePopupLines($target, $previousHp, $previousMp)
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
   * @return array<int, array{text: string, color: Color}> The popup lines to render.
   */
  protected function buildStatChangePopupLines(
    CharacterInterface $target,
    int $previousHp,
    int $previousMp
  ): array
  {
    $hpDelta = $target->stats->currentHp - $previousHp;
    $mpDelta = $target->stats->currentMp - $previousMp;
    $lines = [];

    if ($hpDelta < 0) {
      $lines[] = ['text' => strval(abs($hpDelta)), 'color' => Color::LIGHT_RED];
    } elseif ($hpDelta > 0) {
      $lines[] = ['text' => '+' . $hpDelta, 'color' => Color::LIGHT_GREEN];
    }

    if ($mpDelta < 0) {
      $lines[] = ['text' => '-' . abs($mpDelta) . ' MP', 'color' => Color::LIGHT_CYAN];
    } elseif ($mpDelta > 0) {
      $lines[] = ['text' => '+' . $mpDelta . ' MP', 'color' => Color::LIGHT_CYAN];
    }

    if ($target->isKnockedOut) {
      $lines[] = ['text' => 'KO', 'color' => Color::YELLOW];
    }

    if (empty($lines)) {
      $lines[] = ['text' => 'MISS', 'color' => Color::WHITE];
    }

    return $lines;
  }

  /**
   * Waits for the given number of seconds.
   *
   * @param float $seconds The time to wait in seconds.
   * @return void
   */
  protected function pause(float $seconds): void
  {
    usleep(max(0, intval(round($seconds * 1000000))));
  }
}
