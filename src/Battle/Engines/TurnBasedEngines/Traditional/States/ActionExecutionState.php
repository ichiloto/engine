<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States;

use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\TurnExecutionContext;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;

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
    $this->displayPhase($context, sprintf('%s uses %s!', $actor->name, $actionName), $timings->announcement);
    $this->pause($timings->actionAnimation);
    $this->displayPhase($context, '*SFX*', $timings->effectAnimation);

    $previousHp = $target->stats->currentHp;
    $previousMp = $target->stats->currentMp;
    $resolveAction();

    $this->stepActorBack($context, $actor);
    $this->pause($timings->stepBack);

    $context->ui->characterStatusWindow->setCharacters($context->party->battlers->toArray());
    $context->ui->refresh();

    $hpDelta = $target->stats->currentHp - $previousHp;
    $mpDelta = $target->stats->currentMp - $previousMp;
    $summary = match (true) {
      $hpDelta < 0 => sprintf('%s took %d damage.', $target->name, abs($hpDelta)),
      $hpDelta > 0 && $previousHp <= 0 => sprintf('%s was revived with %d HP.', $target->name, $target->stats->currentHp),
      $hpDelta > 0 => sprintf('%s recovered %d HP.', $target->name, $hpDelta),
      $mpDelta < 0 => sprintf('%s lost %d MP.', $target->name, abs($mpDelta)),
      $mpDelta > 0 => sprintf('%s recovered %d MP.', $target->name, $mpDelta),
      default => sprintf('%s was unaffected.', $target->name),
    };

    if ($target->isKnockedOut) {
      $summary .= sprintf(' %s was defeated.', $target->name);
    }

    $this->displayPhase($context, $summary, $timings->statChanges);
    $this->displayPhase($context, 'Turn over.', $timings->turnOver, hideAfter: true);
    $context->ui->characterNameWindow->setActiveSelection(-1);
    $context->ui->fieldWindow->clearTargetIndicators();
    $context->ui->refreshField();
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
