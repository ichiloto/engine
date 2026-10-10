<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States;

use Assegai\Collections\Stack;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Battle\Actions\GuardAction;
use Ichiloto\Engine\Battle\Actions\ItemBattleAction;
use Ichiloto\Engine\Battle\Actions\PassAction;
use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\BattleResult;
use Ichiloto\Engine\Battle\BattlerBattleView;
use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Battle\BattleCommand;
use Ichiloto\Engine\Battle\BattleCommandType;
use Ichiloto\Engine\Battle\BattleCommandOption;
use Ichiloto\Engine\Battle\EscapePolicy;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\TraditionalTurnBasedBattleEngine;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Battle\BattleTargetPolicy;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Localization\Vocabulary;

/**
 * Handles player-side command, submenu, and target selection for the round.
 *
 * @package Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States
 */
class PlayerActionState extends TurnState
{
  protected const string MODE_COMMAND = 'command';
  protected const string MODE_SUBMENU = 'submenu';
  protected const string MODE_TARGET = 'target';
  /**
   * @var int The active character index.
   */
  protected int $activeCharacterIndex = -1;
  /**
   * @var string The current selection layer.
   */
  protected string $selectionMode = self::MODE_COMMAND;

  /** Read-only ownership for graphical cursors; ATB waiting has no active selector. */
  public function getSelectionMode(): ?string
  {
    return $this->activeCharacterIndex < 0 ? null : $this->selectionMode;
  }
  /**
   * @var int The selected target index within the active target pool.
   */
  protected int $activeTargetIndex = -1;
  protected ?Character $activeCharacter {
    get {
      return $this->engine->battleConfig->partyRoster->battlers[$this->activeCharacterIndex] ?? null;
    }
  }
  /**
   * @var Stack<MenuInterface>|null Reserved menu stack for future nested selectors.
   */
  protected ?Stack $menuStack = null;

  /**
   * @inheritDoc
   */
  public function enter(TurnStateExecutionContext $context): void
  {
    $context->ui->setState($context->ui->playerActionState);
    $this->menuStack = new Stack(MenuInterface::class);
    $this->activeCharacterIndex = -1;
    $this->selectionMode = self::MODE_COMMAND;
    $this->activeTargetIndex = -1;
    $context->ui->commandContextWindow->clear();
    $context->ui->fieldWindow->clearTargetIndicators();
    $context->ui->refreshField();

    if (empty($context->getLivingPartyBattlers())) {
      $this->setStateIfPresent($this->engine->enemyActionState);
      return;
    }

    $this->selectNextCharacter($context, true);
  }

  /**
   * @inheritDoc
   */
  public function update(TurnStateExecutionContext $context): void
  {
    match ($this->selectionMode) {
      self::MODE_COMMAND => $this->handleCommandNavigation($context),
      self::MODE_SUBMENU => $this->handleSubmenuNavigation($context),
      self::MODE_TARGET => $this->handleTargetNavigation($context),
      default => null,
    };

    $this->handleActions($context);
  }

  /**
   * Moves through the primary command list.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return void
   */
  protected function handleCommandNavigation(TurnStateExecutionContext $context): void
  {
    $v = Input::getAxis(AxisName::VERTICAL);

    if (abs($v) < 1) {
      return;
    }

    $context->game->audioManager->playSystemSound(SystemSound::CURSOR);

    if ($v > 0) {
      $context->ui->state->selectNext();
      return;
    }

    $context->ui->state->selectPrevious();
  }

  /**
   * Moves through the active submenu list.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return void
   */
  protected function handleSubmenuNavigation(TurnStateExecutionContext $context): void
  {
    $v = Input::getAxis(AxisName::VERTICAL);

    if (abs($v) < 1) {
      return;
    }

    $context->game->audioManager->playSystemSound(SystemSound::CURSOR);

    if ($v > 0) {
      $context->ui->commandContextWindow->selectNext();
      return;
    }

    $context->ui->commandContextWindow->selectPrevious();
  }

  /**
   * Cycles through valid targets while target selection has focus.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return void
   */
  protected function handleTargetNavigation(TurnStateExecutionContext $context): void
  {
    if ($this->getSelectedOption()?->action->targetScope->number !== ItemScopeNumber::ONE) {
      return;
    }

    $h = Input::getAxis(AxisName::HORIZONTAL);
    $v = Input::getAxis(AxisName::VERTICAL);

    if ($h > 0 || $v > 0) {
      $context->game->audioManager->playSystemSound(SystemSound::CURSOR);
      $this->cycleTarget($context, 1);
      return;
    }

    if ($h < 0 || $v < 0) {
      $context->game->audioManager->playSystemSound(SystemSound::CURSOR);
      $this->cycleTarget($context, -1);
    }
  }

  /**
   * Confirms the current choice or backs out to the previous selection layer.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return void
   */
  protected function handleActions(TurnStateExecutionContext $context): void
  {
    if (Input::isAnyKeyPressed([KeyCode::I, KeyCode::i])) {
      $this->showFocusedInfo($context);
    }

    if (Input::isButtonDown('action')) {
      $context->game->audioManager->playSystemSound(SystemSound::CONFIRM);

      match ($this->selectionMode) {
        self::MODE_COMMAND => $this->beginSubmenuSelection($context),
        self::MODE_SUBMENU => $this->selectSubmenuOption($context),
        self::MODE_TARGET => $this->queueActionForActiveCharacter($context),
        default => null,
      };
    }

    if (! Input::isAnyKeyPressed([KeyCode::C, KeyCode::c])) {
      return;
    }

    $context->game->audioManager->playSystemSound(SystemSound::CANCEL);

    match ($this->selectionMode) {
      self::MODE_TARGET => $this->returnToSubmenuSelection($context),
      self::MODE_SUBMENU => $this->returnToCommandSelection($context),
      default => $this->selectPreviousCharacter($context),
    };
  }

  /**
   * Displays info for the currently focused battle input option.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return void
   */
  protected function showFocusedInfo(TurnStateExecutionContext $context): void
  {
    $infoText = match ($this->selectionMode) {
      self::MODE_COMMAND => $this->resolveCommandInfo($context),
      self::MODE_SUBMENU => $this->resolveSelectedOptionInfo(),
      self::MODE_TARGET => $this->resolveSelectedOptionInfo(true),
      default => null,
    };

    $subjects = $this->selectionMode === self::MODE_TARGET ? $context->ui->fieldWindow->getFocusedBattlers()
      : array_filter([$this->activeCharacter]);
    foreach ($subjects as $subject) {
      $conditions = \Ichiloto\Engine\Battle\Presentation\BattlerConditions::getInfo($subject);
      if ($conditions !== '') { $infoText = trim(($infoText ?? '') . ' ' . $conditions); }
    }
    if ($infoText === null || trim($infoText) === '') {
      return;
    }

    $context->ui->alert($infoText);
  }

  /**
   * Loads the top-level commands for the active character.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return void
   */
  protected function loadCharacterActions(TurnStateExecutionContext $context): void
  {
    if (! $this->activeCharacter) {
      return;
    }

    /** @var TraditionalTurnBasedBattleEngine $engine */
    $engine = $this->engine;
    $ui = $engine->battleConfig->ui;

    $this->selectionMode = self::MODE_COMMAND;
    $this->activeTargetIndex = -1;
    $ui->characterNameWindow->setActiveSelection($this->activeCharacterIndex);
    $ui->commandWindow->commands = BattleCommandCatalog::buildCommands(
      $this->activeCharacter,
      $context->party,
      $context->getGameState(),
      $engine->battleConfig->getEscapePolicy(),
    );
    $ui->commandWindow->focus();
    $ui->commandContextWindow->clear();
    $this->applyTargetingVisuals($context);
  }

  /**
   * Moves to the next character who still needs an action.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param bool $resetToStart Whether to start from the beginning of the party.
   * @return void
   */
  protected function selectNextCharacter(TurnStateExecutionContext $context, bool $resetToStart = false): void
  {
    $partyBattlers = $context->partyRoster->battlers;
    $startIndex = $resetToStart ? 0 : $this->activeCharacterIndex + 1;

    foreach ($partyBattlers as $index => $battler) {
      if ($index < $startIndex) {
        continue;
      }

      $turn = $context->findTurnForBattler($battler);

      if ($battler->isKnockedOut || $turn?->action !== null) {
        continue;
      }

      $this->activeCharacterIndex = $index;
      $this->loadCharacterActions($context);
      return;
    }

    $this->activeCharacterIndex = -1;
    $this->selectionMode = self::MODE_COMMAND;
    $this->activeTargetIndex = -1;
    $context->ui->characterNameWindow->setActiveSelection(-1);
    $context->ui->commandWindow->blur();
    $context->ui->commandContextWindow->clear();
    $context->ui->fieldWindow->clearTargetIndicators();
    $context->ui->refreshField();
    $this->setStateIfPresent($this->engine->enemyActionState);
  }

  /**
   * Opens the active secondary command menu.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return void
   */
  protected function beginSubmenuSelection(TurnStateExecutionContext $context): void
  {
    if (! $this->activeCharacter) {
      return;
    }

    $command = $this->getSelectedCommand($context);

    if ($command === null) {
      return;
    }

    // Guard and Escape resolve at the top level — no submenu.
    switch ($command->type) {
      case BattleCommandType::GUARD:
        $this->queueGuardForActiveCharacter($context);
        return;
      case BattleCommandType::ESCAPE:
        $this->attemptEscape($context);
        return;
      default:
        break;
    }

    $options = BattleCommandCatalog::buildOptions(
      $this->activeCharacter,
      $context->party,
      $command->type,
      $this->getReservedItemCounts($context),
      $context->getGameState(),
    );

    $this->selectionMode = self::MODE_SUBMENU;
    $this->activeTargetIndex = -1;
    $context->ui->commandWindow->setSelectionBlink(false);
    $context->ui->commandContextWindow->setMpBudget($this->activeCharacter->stats->currentMp);
    $context->ui->commandContextWindow->setItems($options, $command->name, $this->getEmptyMenuMessage($command->type));
    $context->ui->commandContextWindow->focus();
    $this->applyTargetingVisuals($context);
  }

  /**
   * Queues a guard for the active character and moves on.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return void
   */
  protected function queueGuardForActiveCharacter(TurnStateExecutionContext $context): void
  {
    $turn = $context->findTurnForBattler($this->activeCharacter);

    if ($turn === null) {
      return;
    }

    $turn->action = new GuardAction(BattleCommandType::GUARD->label());
    $turn->targets = [$this->activeCharacter];
    $this->selectionMode = self::MODE_COMMAND;
    $this->activeTargetIndex = -1;
    $context->ui->alert(sprintf('%s braces for impact.', $this->activeCharacter->name));
    $this->selectNextCharacter($context);
  }

  /**
   * Rolls an escape attempt: the party's speed against the troop's.
   *
   * Success ends the battle immediately with no rewards; failure consumes
   * the active character's turn.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return void
   */
  protected function attemptEscape(TurnStateExecutionContext $context): void
  {
    if ($this->engine->battleConfig->getEscapePolicy() === EscapePolicy::FORBIDDEN) {
      $context->ui->alert('Escape is not available in this battle.');
      $this->selectionMode = self::MODE_COMMAND;
      return;
    }

    $partySpeed = $this->averageSpeed($context->getLivingPartyBattlers());
    $troopSpeed = $this->averageSpeed($context->getLivingTroopBattlers());
    $chance = intval(clamp(50 + ($partySpeed - $troopSpeed) * 2, 5, 95));

    if (rand(1, 100) <= $chance) {
      $scene = $context->game->sceneManager->currentScene;

      if ($scene instanceof BattleScene) {
        play_sound(SystemSound::ESCAPE);
        $scene->result = new BattleResult('Escaped', [
          'The party slipped away!',
          'Press enter to continue.',
        ]);
        $scene->shouldLoadGameOver = false;
        $scene->setState($scene->victoryState);
      }

      return;
    }

    $turn = $context->findTurnForBattler($this->activeCharacter);

    if ($turn !== null) {
      // The failed attempt still costs the character their turn.
      $turn->action = new PassAction(BattleCommandType::ESCAPE->label());
      $turn->targets = [$this->activeCharacter];
    }

    $context->ui->alert('Could not escape!');
    $this->selectionMode = self::MODE_COMMAND;
    $this->activeTargetIndex = -1;
    $this->selectNextCharacter($context);
  }

  /**
   * Returns the average speed of the given battlers.
   *
   * @param CharacterInterface[] $battlers The battlers.
   * @return float The average speed.
   */
  protected function averageSpeed(array $battlers): float
  {
    if (empty($battlers)) {
      return 0.0;
    }

    $total = 0;

    foreach ($battlers as $battler) {
      $total += new BattlerBattleView($battler)->stats->speed;
    }

    return $total / count($battlers);
  }

  /**
   * Moves the active submenu option to target confirmation, including self-only actions.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return void
   */
  protected function selectSubmenuOption(TurnStateExecutionContext $context): void
  {
    $option = $context->ui->commandContextWindow->getActiveItem();

    if (! $option instanceof BattleCommandOption) {
      return;
    }

    // Insufficient MP blocks the option here, at selection time — waiting
    // until execution would let the move fizzle after it was announced.
    if ($this->activeCharacter && $option->mpCost > $this->activeCharacter->stats->currentMp) {
      $context->ui->alert(get_message(
        'battle.insufficient_resource_selection', 'Not enough %5! %1 needs %2 %5, %3 has %4.',
        $option->action->name,
        $option->mpCost,
        $this->activeCharacter->name,
        $this->activeCharacter->stats->currentMp,
        Vocabulary::getTerm('stats.mp', 'MP')
      ));
      return;
    }

    $targetIndexes = $this->getSelectableTargetIndexes($context);

    if (empty($targetIndexes)) {
      return;
    }

    $this->selectionMode = self::MODE_TARGET;
    $this->activeTargetIndex = $targetIndexes[0];
    $context->ui->commandContextWindow->setSelectionBlink(false);
    $this->applyTargetingVisuals($context);
  }

  /**
   * Returns to command selection while preserving queued target markers.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return void
   */
  protected function returnToCommandSelection(TurnStateExecutionContext $context): void
  {
    $this->selectionMode = self::MODE_COMMAND;
    $this->activeTargetIndex = -1;
    $context->ui->commandWindow->setSelectionBlink(true);
    $context->ui->commandContextWindow->clear();
    $this->applyTargetingVisuals($context);
  }

  /**
   * Returns to the submenu list while preserving queued target markers.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return void
   */
  protected function returnToSubmenuSelection(TurnStateExecutionContext $context): void
  {
    $this->selectionMode = self::MODE_SUBMENU;
    $this->activeTargetIndex = -1;
    $context->ui->commandContextWindow->setSelectionBlink(true);
    $this->applyTargetingVisuals($context);
  }

  /**
   * Cycles target focus through the currently selectable battlers.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param int $step The target-step direction.
   * @return void
   */
  protected function cycleTarget(TurnStateExecutionContext $context, int $step): void
  {
    $targetIndexes = $this->getSelectableTargetIndexes($context);

    if (empty($targetIndexes)) {
      return;
    }

    $currentPosition = array_search($this->activeTargetIndex, $targetIndexes, true);
    $currentPosition = is_int($currentPosition) ? $currentPosition : 0;
    $targetCount = count($targetIndexes);
    $nextPosition = $currentPosition + $step;

    if ($nextPosition < 0) {
      $nextPosition = $targetCount - 1;
    } elseif ($nextPosition >= $targetCount) {
      $nextPosition = 0;
    }

    $this->activeTargetIndex = $targetIndexes[$nextPosition];
    $this->applyTargetingVisuals($context);
  }

  /**
   * Queues the currently selected submenu action for the active character.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return void
   */
  protected function queueActionForActiveCharacter(TurnStateExecutionContext $context): void
  {
    if (! $this->activeCharacter) {
      return;
    }

    $selectedOption = $context->ui->commandContextWindow->getActiveItem();
    $turn = $context->findTurnForBattler($this->activeCharacter);
    $targets = $this->resolveSelectedTargets($context, $selectedOption);

    if (! $selectedOption instanceof BattleCommandOption || $turn === null || empty($targets)) {
      return;
    }

    if ($selectedOption->mpCost > $this->activeCharacter->stats->currentMp) {
      $context->ui->alert(get_message(
        'battle.insufficient_resource_selection', 'Not enough %5! %1 needs %2 %5, %3 has %4.',
        $selectedOption->action->name,
        $selectedOption->mpCost,
        $this->activeCharacter->name,
        $this->activeCharacter->stats->currentMp,
        Vocabulary::getTerm('stats.mp', 'MP')
      ));
      return;
    }

    $turn->action = $selectedOption->action;
    $turn->targets = $targets;
    $this->selectionMode = self::MODE_COMMAND;
    $this->activeTargetIndex = -1;

    $targetNames = implode(', ', array_map(fn(CharacterInterface $target) => $target->name, $targets));
    $context->ui->alert(sprintf('%s queued %s on %s.', $this->activeCharacter->name, $selectedOption->action->name, $targetNames));
    $this->selectNextCharacter($context);
  }

  /**
   * Rewinds to the previous queued character so their action can be changed.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return void
   */
  protected function selectPreviousCharacter(TurnStateExecutionContext $context): void
  {
    $partyBattlers = $context->partyRoster->battlers;
    $startIndex = $this->activeCharacterIndex < 0 ? count($partyBattlers) - 1 : $this->activeCharacterIndex - 1;

    for ($index = $startIndex; $index >= 0; $index--) {
      $battler = $partyBattlers[$index];
      $turn = $context->findTurnForBattler($battler);

      if ($battler->isKnockedOut || $turn === null || $turn->action === null) {
        continue;
      }

      $turn->action = null;
      $turn->targets = [];
      $this->activeCharacterIndex = $index;
      $this->loadCharacterActions($context);
      $context->ui->alert(sprintf('%s action cleared.', $battler->name));
      return;
    }

    if ($this->activeCharacterIndex < 0) {
      $this->selectNextCharacter($context, true);
    }
  }

  /**
   * Applies battlefield queue and focus visuals for the current selection state.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return void
   */
  protected function applyTargetingVisuals(TurnStateExecutionContext $context): void
  {
    $queuedPartyTargets = [];
    $queuedTroopTargets = [];
    $partyBattlers = $context->partyRoster->battlers;
    $troopMembers = $context->troop->members->toArray();

    foreach ($context->getTurns() as $turn) {
      if ($turn->action === null) {
        continue;
      }

      foreach ($turn->targets as $target) {
        $partyIndex = array_search($target, $partyBattlers, true);

        if (is_int($partyIndex)) {
          $queuedPartyTargets[$partyIndex] = ($queuedPartyTargets[$partyIndex] ?? 0) + 1;
          continue;
        }

        $troopIndex = array_search($target, $troopMembers, true);

        if (is_int($troopIndex)) {
          $queuedTroopTargets[$troopIndex] = ($queuedTroopTargets[$troopIndex] ?? 0) + 1;
        }
      }
    }

    $context->ui->fieldWindow->setPartyTargetQueue($queuedPartyTargets);
    $context->ui->fieldWindow->setTroopTargetQueue($queuedTroopTargets);
    $context->ui->fieldWindow->clearPartyFocus();
    $context->ui->fieldWindow->clearTroopFocus();

    if ($this->selectionMode === self::MODE_TARGET && $this->activeTargetIndex >= 0) {
      $option = $this->getSelectedOption();
      $indexes = $option?->action->targetScope->number === ItemScopeNumber::ONE
        ? [$this->activeTargetIndex] : $this->getSelectableTargetIndexes($context);
      $pool = $option === null ? [] : $this->getSelectionPool($context, $option);
      $partyFocus = $troopFocus = [];
      foreach ($indexes as $index) {
        $target = $pool[$index] ?? null;
        $partyIndex = array_search($target, $partyBattlers, true);
        $troopIndex = array_search($target, $troopMembers, true);
        if (is_int($partyIndex)) { $partyFocus[] = $partyIndex; }
        if (is_int($troopIndex)) { $troopFocus[] = $troopIndex; }
      }
      $context->ui->fieldWindow->focusPartyBattlers($partyFocus, blink: true);
      $context->ui->fieldWindow->focusTroopBattlers($troopFocus, blink: true);
    }

    // Selection layers sit over a battlefield that can also be touched by
    // alerts and other transient UI. Recompose every battle layer here so
    // opening Skill, Magic, Item, Summon, or targeting never leaves only the
    // controls visible after an overlay changed the same terminal cells.
    $context->ui->recomposeField();
  }

  /**
   * Returns the currently selected top-level command name.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return string|null The selected command name.
   */
  protected function getSelectedCommandName(TurnStateExecutionContext $context): ?string
  {
    return $this->getSelectedCommand($context)?->name;
  }

  protected function getSelectedCommand(TurnStateExecutionContext $context): ?BattleCommand
  {
    return $context->ui->commandWindow->getActiveCommand();
  }

  /**
   * Returns help text for the currently focused top-level command.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return string|null The command help text.
   */
  protected function resolveCommandInfo(TurnStateExecutionContext $context): ?string
  {
    return $this->getSelectedCommand($context)?->type->helpText();
  }

  /**
   * Returns the submenu option that currently has focus.
   *
   * @return BattleCommandOption|null The active submenu option.
   */
  protected function getSelectedOption(): ?BattleCommandOption
  {
    return $this->engine->battleConfig->ui->commandContextWindow->getActiveItem();
  }

  /**
   * Returns help text for the currently focused submenu option.
   *
   * @param bool $appendTargetHint Whether to append target-selection guidance.
   * @return string|null The option help text.
   */
  protected function resolveSelectedOptionInfo(bool $appendTargetHint = false): ?string
  {
    $selectedOption = $this->getSelectedOption();

    if (! $selectedOption instanceof BattleCommandOption) {
      $emptyMessage = $this->engine->battleConfig->ui->commandContextWindow->getEmptyMessage();
      return $emptyMessage !== '' ? $emptyMessage : null;
    }

    $description = $selectedOption->description !== ''
      ? $selectedOption->description
      : sprintf('Use %s.', $selectedOption->action->name);

    if (! $appendTargetHint) {
      return $description;
    }

    return trim($description . match ($selectedOption->action->targetScope->number) {
      ItemScopeNumber::ALL => ' Confirm all highlighted targets, or press C to return.',
      ItemScopeNumber::RANDOM => ' Confirm random targets from the highlighted group, or press C to return.',
      ItemScopeNumber::ONE => ' Choose a target.',
    });
  }

  /**
   * Returns the battler indexes that can currently be targeted.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return int[] The valid target indexes.
   */
  protected function getSelectableTargetIndexes(TurnStateExecutionContext $context): array
  {
    $selectedOption = $this->getSelectedOption();

    if (! $selectedOption instanceof BattleCommandOption || $this->activeCharacter === null) {
      return [];
    }

    $eligible = BattleTargetPolicy::getEligibleTargets($selectedOption->action->targetScope,
      $this->activeCharacter, $context->partyRoster->battlers, $context->troop->members->toArray());
    $indexes = [];
    foreach ($this->getSelectionPool($context, $selectedOption) as $index => $target) {
      if (in_array($target, $eligible, true)) { $indexes[] = $index; }
    }
    return $indexes;
  }

  /**
   * Resolves the final target list for the selected submenu option.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param BattleCommandOption|null $selectedOption The selected submenu option.
   * @return CharacterInterface[] The targets to queue.
   */
  protected function resolveSelectedTargets(
    TurnStateExecutionContext $context,
    ?BattleCommandOption $selectedOption
  ): array
  {
    if (! $selectedOption instanceof BattleCommandOption || $this->activeCharacter === null) {
      return [];
    }

    $selected = $this->getSelectionPool($context, $selectedOption)[$this->activeTargetIndex] ?? null;
    return BattleTargetPolicy::resolveTargets($selectedOption->action->targetScope, $this->activeCharacter,
      $context->partyRoster->battlers, $context->troop->members->toArray(),
      $selected === null || $selectedOption->action->targetScope->number === ItemScopeNumber::RANDOM ? [] : [$selected],
      $this->engine->random);
  }

  /**
   * Keeps focus indexes stable while eligibility changes within either side.
   * @return CharacterInterface[]
   */
  protected function getSelectionPool(TurnStateExecutionContext $context, BattleCommandOption $option): array
  {
    return match ($option->action->targetScope->side) {
      ItemScopeSide::ALLY, ItemScopeSide::USER => $context->partyRoster->battlers,
      ItemScopeSide::ENEMY => $context->troop->members->toArray(),
      ItemScopeSide::ENEMY_ALLY => [...$context->partyRoster->battlers, ...$context->troop->members->toArray()],
      ItemScopeSide::NONE => [],
    };
  }

  /**
   * Counts how many copies of each item have already been queued this round.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return array<string, int> Reserved item counts keyed by stable definition id.
   */
  protected function getReservedItemCounts(TurnStateExecutionContext $context): array
  {
    $reservedCounts = [];

    foreach ($context->getTurns() as $turn) {
      if (! $turn->action instanceof ItemBattleAction) {
        continue;
      }

      $definitionId = $turn->action->item->id;
      $reservedCounts[$definitionId] = ($reservedCounts[$definitionId] ?? 0) + 1;
    }

    return $reservedCounts;
  }

  /**
   * Returns the empty-state message for the requested submenu.
   *
   * @param BattleCommandType|string $commandName The semantic command, or a legacy name/id.
   * @return string The empty-state message.
   */
  protected function getEmptyMenuMessage(BattleCommandType|string $commandName): string
  {
    $type = $commandName instanceof BattleCommandType ? $commandName : BattleCommandType::fromCommandName($commandName);
    return $type?->emptyMessage() ?? 'Nothing available.';
  }
}
