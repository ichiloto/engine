<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States;

use Assegai\Collections\Stack;
use Ichiloto\Engine\Battle\Actions\ItemBattleAction;
use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Battle\BattleCommandOption;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\TraditionalTurnBasedBattleEngine;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;

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
  /**
   * @var int The selected target index within the active target pool.
   */
  protected int $activeTargetIndex = -1;
  protected ?Character $activeCharacter {
    get {
      return $this->engine->battleConfig->party->battlers->toArray()[$this->activeCharacterIndex] ?? null;
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
      $this->setState($this->engine->enemyActionState);
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
    $h = Input::getAxis(AxisName::HORIZONTAL);
    $v = Input::getAxis(AxisName::VERTICAL);

    if ($h > 0 || $v > 0) {
      $this->cycleTarget($context, 1);
      return;
    }

    if ($h < 0 || $v < 0) {
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
    $ui->commandWindow->commands = array_map(
      fn(BattleAction $action) => $action->name,
      $this->activeCharacter->commandAbilities
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
    $partyBattlers = $context->party->battlers->toArray();
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
    $this->setState($this->engine->enemyActionState);
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

    $commandName = $this->getSelectedCommandName($context);

    if ($commandName === null) {
      return;
    }

    $options = BattleCommandCatalog::buildOptions(
      $this->activeCharacter,
      $context->party,
      $commandName,
      $this->getReservedItemCounts($context)
    );

    $this->selectionMode = self::MODE_SUBMENU;
    $this->activeTargetIndex = -1;
    $context->ui->commandWindow->setSelectionBlink(false);
    $context->ui->commandContextWindow->setItems($options, $commandName, $this->getEmptyMenuMessage($commandName));
    $context->ui->commandContextWindow->focus();
    $this->applyTargetingVisuals($context);
  }

  /**
   * Confirms the active submenu option and either moves to targeting or queues it directly.
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

    if ($option->targetSide === ItemScopeSide::USER) {
      $this->queueActionForActiveCharacter($context);
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
    $partyBattlers = $context->party->battlers->toArray();
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
    $partyBattlers = $context->party->battlers->toArray();
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
      match ($this->getSelectedOption()?->targetSide) {
        ItemScopeSide::ALLY => $context->ui->fieldWindow->focusPartyBattler($this->activeTargetIndex, blink: true),
        default => $context->ui->fieldWindow->focusOnTroopBattler($this->activeTargetIndex, blink: true),
      };
    }

    $context->ui->fieldWindow->redrawTargetIndicators();
  }

  /**
   * Returns the currently selected top-level command name.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return string|null The selected command name.
   */
  protected function getSelectedCommandName(TurnStateExecutionContext $context): ?string
  {
    $activeCommandIndex = $context->ui->commandWindow->activeCommandIndex;
    return $context->ui->commandWindow->commands[$activeCommandIndex] ?? null;
  }

  /**
   * Returns help text for the currently focused top-level command.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return string|null The command help text.
   */
  protected function resolveCommandInfo(TurnStateExecutionContext $context): ?string
  {
    return match (strtolower((string) $this->getSelectedCommandName($context))) {
      'attack' => 'Choose a physical attack to strike an enemy.',
      'skill' => 'Use one of this character\'s battle abilities.',
      'magic' => 'Cast a learned spell that can be used in battle.',
      'summon' => 'Call a summon or esper to aid the party.',
      'item' => 'Use a battle item from the party inventory.',
      default => null,
    };
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

    return trim($description . ' Choose a target.');
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

    if (! $selectedOption instanceof BattleCommandOption) {
      return [];
    }

    return match ($selectedOption->targetSide) {
      ItemScopeSide::ALLY => $this->getMatchingPartyIndexes($context, $selectedOption->targetStatus),
      ItemScopeSide::USER => [$this->activeCharacterIndex],
      default => $this->getMatchingTroopIndexes($context, $selectedOption->targetStatus),
    };
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
    if (! $selectedOption instanceof BattleCommandOption) {
      return [];
    }

    if ($selectedOption->targetSide === ItemScopeSide::USER && $this->activeCharacter) {
      return [$this->activeCharacter];
    }

    return match ($selectedOption->targetSide) {
      ItemScopeSide::ALLY => $this->resolvePartyTargets($context, $selectedOption->targetStatus),
      default => $this->resolveTroopTargets($context, $selectedOption->targetStatus),
    };
  }

  /**
   * Resolves the currently selected party targets.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param ItemScopeStatus $status The required target status.
   * @return CharacterInterface[] The resolved party targets.
   */
  protected function resolvePartyTargets(TurnStateExecutionContext $context, ItemScopeStatus $status): array
  {
    $partyBattlers = $context->party->battlers->toArray();
    $target = $partyBattlers[$this->activeTargetIndex] ?? null;

    return $target instanceof CharacterInterface && $this->matchesStatus($target, $status)
      ? [$target]
      : [];
  }

  /**
   * Resolves the currently selected troop targets.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param ItemScopeStatus $status The required target status.
   * @return CharacterInterface[] The resolved troop targets.
   */
  protected function resolveTroopTargets(TurnStateExecutionContext $context, ItemScopeStatus $status): array
  {
    $troopMembers = $context->troop->members->toArray();
    $target = $troopMembers[$this->activeTargetIndex] ?? null;

    return $target instanceof CharacterInterface && $this->matchesStatus($target, $status)
      ? [$target]
      : [];
  }

  /**
   * Returns party battler indexes that match the requested status.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param ItemScopeStatus $status The required target status.
   * @return int[] The matching party indexes.
   */
  protected function getMatchingPartyIndexes(TurnStateExecutionContext $context, ItemScopeStatus $status): array
  {
    $indexes = [];

    foreach ($context->party->battlers->toArray() as $index => $battler) {
      if ($this->matchesStatus($battler, $status)) {
        $indexes[] = $index;
      }
    }

    return $indexes;
  }

  /**
   * Returns troop battler indexes that match the requested status.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param ItemScopeStatus $status The required target status.
   * @return int[] The matching troop indexes.
   */
  protected function getMatchingTroopIndexes(TurnStateExecutionContext $context, ItemScopeStatus $status): array
  {
    $indexes = [];

    foreach ($context->troop->members->toArray() as $index => $battler) {
      if ($battler instanceof Enemy && $this->matchesStatus($battler, $status)) {
        $indexes[] = $index;
      }
    }

    return $indexes;
  }

  /**
   * Checks whether the battler satisfies the requested target status.
   *
   * @param CharacterInterface $battler The battler to inspect.
   * @param ItemScopeStatus $status The requested target status.
   * @return bool True when the battler matches the requested status.
   */
  protected function matchesStatus(CharacterInterface $battler, ItemScopeStatus $status): bool
  {
    return match ($status) {
      ItemScopeStatus::DEAD => $battler->isKnockedOut,
      ItemScopeStatus::ANY => true,
      default => ! $battler->isKnockedOut,
    };
  }

  /**
   * Counts how many copies of each item have already been queued this round.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return array<string, int> Reserved item counts keyed by item name.
   */
  protected function getReservedItemCounts(TurnStateExecutionContext $context): array
  {
    $reservedCounts = [];

    foreach ($context->getTurns() as $turn) {
      if (! $turn->action instanceof ItemBattleAction) {
        continue;
      }

      $itemName = $turn->action->item->name;
      $reservedCounts[$itemName] = ($reservedCounts[$itemName] ?? 0) + 1;
    }

    return $reservedCounts;
  }

  /**
   * Returns the empty-state message for the requested submenu.
   *
   * @param string $commandName The selected top-level command name.
   * @return string The empty-state message.
   */
  protected function getEmptyMenuMessage(string $commandName): string
  {
    return match (strtolower($commandName)) {
      'attack' => 'No attacks.',
      'skill' => 'No skills.',
      'magic' => 'No magic.',
      'summon' => 'No summons.',
      'item' => 'No items.',
      default => 'Nothing available.',
    };
  }
}
