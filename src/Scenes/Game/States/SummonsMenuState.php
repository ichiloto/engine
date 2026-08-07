<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Exception;
use Ichiloto\Engine\Battle\BattleCommandType;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Displays the summon-management screen for a single party member.
 *
 * Lists every authored summon with its move and wielder rules so players can
 * always review the summons at their disposal. Summons that declare a
 * wielder policy can be assigned or released per character, with eligibility
 * (role or named character) and tenancy (exclusive or shared) enforced by
 * the party; summons without a policy are shown as open to the whole party.
 *
 * @package Ichiloto\Engine\Scenes\Game\States
 */
class SummonsMenuState extends GameSceneState
{
  protected const int MENU_WIDTH = 110;
  protected const int SUMMARY_PANEL_HEIGHT = 6;
  protected const int LIST_PANEL_HEIGHT = 25;
  protected const int INFO_PANEL_HEIGHT = 4;

  /**
   * @var Character|null The character currently being viewed.
   */
  public ?Character $character = null;
  /**
   * @var SummonCutsceneDefinition[] The assignable summons.
   */
  protected array $summons = [];
  /**
   * @var int The active list index.
   */
  protected int $activeIndex = 0;
  /**
   * @var int The centered left margin.
   */
  protected int $leftMargin = 0;
  /**
   * @var int The centered top margin.
   */
  protected int $topMargin = 0;
  /**
   * @var BorderPackInterface|null The border pack for the screen.
   */
  protected ?BorderPackInterface $borderPack = null;
  /**
   * @var Window|null The character summary panel.
   */
  protected ?Window $summaryPanel = null;
  /**
   * @var Window|null The summon list panel.
   */
  protected ?Window $listPanel = null;
  /**
   * @var Window|null The bottom description panel.
   */
  protected ?Window $infoPanel = null;

  /**
   * Returns every authored summon definition.
   *
   * @return SummonCutsceneDefinition[] The summon definitions.
   */
  public static function loadSummons(): array
  {
    return new SummonCutsceneLibrary()->load();
  }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    Console::clear();
    $this->getGameScene()->locationHUDWindow->deactivate();
    $this->character ??= $this->getGameScene()->party->leader;
    $this->summons = self::loadSummons();
    $this->activeIndex = 0;
    $this->calculateMargins();
    $this->initializeUI();
    $this->refreshUI();
  }

  /**
   * @inheritDoc
   */
  public function execute(?SceneStateContext $context = null): void
  {
    if ($this->handleCharacterCycling()) {
      return;
    }

    $this->handleNavigation();
    $this->handleActions();
  }

  /**
   * Centers the screen inside the terminal.
   *
   * @return void
   */
  protected function calculateMargins(): void
  {
    $totalHeight = self::SUMMARY_PANEL_HEIGHT + self::LIST_PANEL_HEIGHT + self::INFO_PANEL_HEIGHT;
    $this->leftMargin = max(0, intdiv(get_screen_width() - self::MENU_WIDTH, 2));
    $this->topMargin = max(0, intdiv(get_screen_height() - $totalHeight, 2));
  }

  /**
   * Builds the screen's windows.
   *
   * @return void
   */
  protected function initializeUI(): void
  {
    $this->borderPack = new DefaultBorderPack();

    $this->summaryPanel = new Window(
      BattleCommandType::SUMMON->label() . 's',
      '',
      new Vector2($this->leftMargin, $this->topMargin),
      self::MENU_WIDTH,
      self::SUMMARY_PANEL_HEIGHT,
      $this->borderPack
    );

    $this->listPanel = new Window(
      '',
      '',
      new Vector2($this->leftMargin, $this->topMargin + self::SUMMARY_PANEL_HEIGHT),
      self::MENU_WIDTH,
      self::LIST_PANEL_HEIGHT,
      $this->borderPack
    );

    $this->infoPanel = new Window(
      'Info',
      'enter:Assign/Release  tab:Next  c:Cancel',
      new Vector2($this->leftMargin, $this->topMargin + self::SUMMARY_PANEL_HEIGHT + self::LIST_PANEL_HEIGHT),
      self::MENU_WIDTH,
      self::INFO_PANEL_HEIGHT,
      $this->borderPack
    );
  }

  /**
   * Redraws every panel from the current state.
   *
   * @return void
   */
  protected function refreshUI(): void
  {
    $this->refreshSummaryPanel();
    $this->refreshListPanel();
    $this->refreshInfoPanel();
  }

  /**
   * Redraws the character summary panel.
   *
   * @return void
   */
  protected function refreshSummaryPanel(): void
  {
    if (! $this->character) {
      return;
    }

    $policySummons = array_filter(
      $this->summons,
      static fn(SummonCutsceneDefinition $definition): bool => $definition->wielders !== null
    );
    $assignedCount = count(array_filter(
      $policySummons,
      fn(SummonCutsceneDefinition $definition): bool => $this->character->hasSummon($definition->id)
    ));

    $tallyLine = empty($policySummons)
      ? sprintf(' Summons: %d', count($this->summons))
      : sprintf(' Assigned: %d / %d', $assignedCount, count($policySummons));

    $this->summaryPanel->setContent([
      ' ' . $this->character->name,
      sprintf(' Role: %s', $this->character->role->name),
      $tallyLine,
      ' Use the arrow keys to select a summon.',
    ]);
    $this->summaryPanel->render();
  }

  /**
   * Redraws the summon list panel.
   *
   * @return void
   */
  protected function refreshListPanel(): void
  {
    $content = [];

    if (empty($this->summons)) {
      $content[] = ' No assignable summons.';
    }

    foreach ($this->summons as $index => $definition) {
      $prefix = $index === $this->activeIndex ? '>' : ' ';
      $name = TerminalText::padRight($definition->name, 22);
      $move = TerminalText::padRight($definition->moveName ?? '', 26);
      $status = $this->describeStatus($definition);
      $content[] = sprintf(' %s %s %s %s', $prefix, $name, $move, $status);
    }

    $content = array_pad($content, self::LIST_PANEL_HEIGHT - 2, '');
    $this->listPanel->setContent($content);
    $this->listPanel->render();
  }

  /**
   * Redraws the bottom description panel.
   *
   * @return void
   */
  protected function refreshInfoPanel(): void
  {
    $definition = $this->summons[$this->activeIndex] ?? null;
    $this->infoPanel->setContent([
      ' ' . trim($definition?->description ?? ''),
      ' ' . ($definition !== null ? $this->describeWielderRule($definition) : ''),
    ]);
    $this->infoPanel->render();
  }

  /**
   * Describes the summon's assignment status for the active character.
   *
   * @param SummonCutsceneDefinition $definition The summon definition.
   * @return string The status label.
   */
  protected function describeStatus(SummonCutsceneDefinition $definition): string
  {
    if (! $this->character) {
      return '';
    }

    if ($definition->wielders === null) {
      return '[Open to all]';
    }

    if ($this->character->hasSummon($definition->id)) {
      return '[Assigned]';
    }

    if (! $definition->wielders->allowsCharacter($this->character)) {
      return '[Not eligible]';
    }

    $holders = $this->party->getSummonHolders($definition->id);

    if ($definition->wielders->isExclusive() && ! empty($holders)) {
      return sprintf('[Held by %s]', $holders[0]->name);
    }

    return '[Available]';
  }

  /**
   * Describes the summon's wielder rules for the info panel.
   *
   * @param SummonCutsceneDefinition $definition The summon definition.
   * @return string The wielder rule text.
   */
  protected function describeWielderRule(SummonCutsceneDefinition $definition): string
  {
    $policy = $definition->wielders;

    if ($policy === null) {
      return 'Answers the whole party.';
    }

    $eligibility = match ($policy->mode) {
      $policy::MODE_ROLES => sprintf('Wielders: %s.', implode(', ', $policy->roles)),
      $policy::MODE_CHARACTERS => sprintf('Wielders: %s.', implode(', ', $policy->characters)),
      default => 'Any party member may bear this summon.',
    };

    return $policy->isExclusive()
      ? $eligibility . ' One bearer at a time.'
      : $eligibility;
  }

  /**
   * Handles list navigation.
   *
   * @return void
   */
  protected function handleNavigation(): void
  {
    $v = Input::getAxis(AxisName::VERTICAL);

    if (abs($v) > 0 && count($this->summons) > 0) {
      $this->activeIndex = wrap(
        $this->activeIndex + ($v > 0 ? 1 : -1),
        0,
        count($this->summons) - 1
      );
      $this->refreshListPanel();
      $this->refreshInfoPanel();
    }
  }

  /**
   * Handles assignment toggling and leaving the screen.
   *
   * @return void
   * @throws Exception If an error occurs while alerting the player.
   */
  protected function handleActions(): void
  {
    if (Input::isButtonDown('back') || Input::isButtonDown('cancel')) {
      $this->setState($this->getGameScene()->mainMenuState);
      return;
    }

    if (! Input::isButtonDown('confirm')) {
      return;
    }

    $definition = $this->summons[$this->activeIndex] ?? null;

    if ($definition === null || ! $this->character) {
      return;
    }

    if ($definition->wielders === null) {
      alert(sprintf('%s answers the whole party — no assignment needed.', $definition->name));
      return;
    }

    if ($this->character->hasSummon($definition->id)) {
      $this->party->unassignSummon($definition->id, $this->character);
      alert(sprintf('%s released %s.', $this->character->name, $definition->name));
    } elseif ($this->party->assignSummon($definition, $this->character)) {
      alert(sprintf('%s can now call %s.', $this->character->name, $definition->name));
    } else {
      alert($this->describeAssignmentFailure($definition));
    }

    $this->refreshUI();
  }

  /**
   * Explains why the summon could not be assigned.
   *
   * @param SummonCutsceneDefinition $definition The summon definition.
   * @return string The failure message.
   */
  protected function describeAssignmentFailure(SummonCutsceneDefinition $definition): string
  {
    if (! $definition->wielders?->allowsCharacter($this->character)) {
      return sprintf('%s cannot wield %s.', $this->character->name, $definition->name);
    }

    $holders = $this->party->getSummonHolders($definition->id);

    if (! empty($holders)) {
      return sprintf('%s is already bound to %s.', $definition->name, $holders[0]->name);
    }

    return sprintf('%s cannot be assigned.', $definition->name);
  }

  /**
   * Handles the party-member cycling shortcuts.
   *
   * @return bool True when the active character changed.
   */
  protected function handleCharacterCycling(): bool
  {
    $offset = match (true) {
      $this->isNextCharacterRequested() => 1,
      $this->isPreviousCharacterRequested() => -1,
      default => 0,
    };

    if ($offset === 0) {
      return false;
    }

    $characters = $this->getGameScene()->party->members->toArray();
    $totalCharacters = count($characters);

    if ($totalCharacters < 2) {
      return false;
    }

    $currentIndex = array_search($this->character, $characters, true);
    $currentIndex = ($currentIndex === false) ? 0 : $currentIndex;
    $this->character = $characters[wrap($currentIndex + $offset, 0, $totalCharacters - 1)];
    $this->refreshUI();

    return true;
  }

  /**
   * @inheritDoc
   */
  public function resume(): void
  {
    $this->refreshUI();
  }
}
