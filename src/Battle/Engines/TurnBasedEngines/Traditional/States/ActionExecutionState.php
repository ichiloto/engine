<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States;

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

    if ($turn === null) {
      $this->setState($this->engine->turnResolutionState);
      return;
    }

    if ($turn->battler->isKnockedOut) {
      $context->advanceTurn();
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

    $context->advanceTurn();

    if ($context->getCurrentTurn() === null) {
      $this->setState($this->engine->turnResolutionState);
    }
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
    $this->displayAnnouncementPhase(
      $context,
      $actor,
      $action,
      sprintf('%s uses %s!', $actor->name, $actionName),
      $timings->announcement
    );
    $this->pause($timings->actionAnimation);
    $this->displayPhase($context, '*SFX*', $timings->effectAnimation);

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
    CharacterInterface $actor,
    ?BattleAction $action,
    string $message,
    float $delaySeconds
  ): void
  {
    $context->ui->showMessage($message);

    if (! $this->shouldAnimateMagicCastEffect($context, $actor, $action)) {
      $this->pause($delaySeconds);
      return;
    }

    $this->playMagicCastEffect($context, $actor, $action, $delaySeconds);
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
   * Determines whether the announcement phase should render the caster magic effect.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param CharacterInterface $actor The acting battler.
   * @param BattleAction|null $action The action being announced.
   * @return bool
   */
  protected function shouldAnimateMagicCastEffect(
    TurnStateExecutionContext $context,
    CharacterInterface $actor,
    ?BattleAction $action
  ): bool
  {
    if (! $actor instanceof Character) {
      return false;
    }

    if (! $action instanceof SkillBattleAction || ! $action->skill instanceof MagicSkill) {
      return false;
    }

    return is_int(array_search($actor, $context->party->battlers->toArray(), true));
  }

  /**
   * Plays the clockwise caster effect for magic announced by a party battler.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param CharacterInterface $actor The acting battler.
   * @param BattleAction|null $action The resolved battle action.
   * @param float $delaySeconds The time budget for the full sequence.
   * @return void
   */
  protected function playMagicCastEffect(
    TurnStateExecutionContext $context,
    CharacterInterface $actor,
    ?BattleAction $action,
    float $delaySeconds
  ): void
  {
    if (! $actor instanceof Character || ! $action instanceof SkillBattleAction || ! $action->skill instanceof MagicSkill) {
      $this->pause($delaySeconds);
      return;
    }

    $partyBattlers = $context->party->battlers->toArray();
    $actorIndex = array_search($actor, $partyBattlers, true);

    if (! is_int($actorIndex)) {
      $this->pause($delaySeconds);
      return;
    }

    $frameCount = 4;
    $frameDuration = $frameCount > 0 ? $delaySeconds / $frameCount : 0.0;
    $color = $this->resolveMagicCastEffectColor($action->skill);

    for ($frame = 0; $frame < $frameCount; $frame++) {
      $context->ui->fieldWindow->showPartyMagicCastEffect($actor, $actorIndex, $color, $frame);
      $this->pause($frameDuration);
    }

    $context->ui->fieldWindow->clearMagicCastEffects();
  }

  /**
   * Resolves the caster effect color for the provided magic skill.
   *
   * @param MagicSkill $skill The magic skill being announced.
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
