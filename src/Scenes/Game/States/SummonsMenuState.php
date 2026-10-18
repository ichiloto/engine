<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Localization\Vocabulary;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\Presentation\MenuCanvasState;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\MenuRow;
use Ichiloto\Engine\UI\Presentation\MenuRowValue;
use Ichiloto\Engine\UI\Presentation\SummonsMenuContent;
use Ichiloto\Engine\UI\Presentation\SummonsMenuPresentation;
use Ichiloto\Engine\UI\Text\TextViewport;
use Ichiloto\Engine\UI\Text\TextPage;


use Exception;
use Ichiloto\Engine\Battle\BattleCommandType;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
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
 * Lists summons available to the current character without exposing authored
 * eligibility rosters. Summons that declare a wielder policy can be assigned
 * or released per character, with eligibility
 * (role or named character) and tenancy (exclusive or shared) enforced by
 * the party; summons without a policy are shown as open to the whole party.
 *
 * @package Ichiloto\Engine\Scenes\Game\States
 */
class SummonsMenuState extends GameSceneState implements CanvasProviderInterface
{
  use MenuCanvasState;

  private ?TextViewport $detailViewport = null;
  private ?TextPage $lastDetailPage = null;

  protected function composeMenuCanvas(MenuPresentationCatalog $theme, float $time): ?PresentationCanvas
  {
    $content = $this->getPresentationContent();
    if ($content === null) { return null; }
    $page = null;
    if ($this->viewingDetail) {
      [$columns, $rows] = SummonsMenuPresentation::getDetailPageSize($content, $theme);
      $page = $this->detailViewport->page($columns, $rows);
    }
    $canvas = SummonsMenuPresentation::compose($content, $theme, $page, $this->menuInfoText, $time);
    if ($page !== null) { $this->lastDetailPage = $page; }
    return $canvas;
  }

  public function getPresentationContent(): ?SummonsMenuContent
  {
    if ($this->character === null) { return null; }
    $rows = [];
    foreach ($this->summons as $index => $definition) {
      $rows[] = new MenuRow((string)$index, $definition->name,
        [new MenuRowValue($definition->moveName ?? ''), new MenuRowValue(trim($this->describeStatus($definition), '[]'))],
        selected: $index === $this->activeIndex, focused: $index === $this->activeIndex && !$this->viewingDetail);
    }
    $definition = $this->summons[$this->activeIndex] ?? null;
    return new SummonsMenuContent($this->character,
      Vocabulary::getTerm('command.' . BattleCommandType::SUMMON->value, 'Summons'),
      array_map(trim(...), $this->getSummaryLines()), $rows, $this->activeIndex, $this->viewingDetail,
      $definition?->description ?? '',
      $definition !== null && $this->character->hasSummon($definition->id) ? 'Assigned' : null, $this->getActionHints());
  }

  /** @return list<\Ichiloto\Engine\IO\ActionHint> */
  protected function getActionHints(): array
  {
    $definition = $this->summons[$this->activeIndex] ?? null;
    $hints = [ActionHints::resolve('cancel', $this->viewingDetail ? 'Back' : 'Cancel'),
      ActionHints::resolve('character_next', 'Next', KeyCode::TAB),
      ActionHints::resolve('character_previous', 'Previous', KeyCode::SHIFT_TAB)];
    if (!$this->viewingDetail || $definition?->wielders !== null) {
      array_unshift($hints, ActionHints::resolve('confirm', !$this->viewingDetail ? 'Details'
        : ($this->character?->hasSummon($definition->id) ? 'Release' : 'Assign')));
    }
    if ($this->viewingDetail) {
      $hints[] = ActionHints::resolve('up', 'Scroll up');
      $hints[] = ActionHints::resolve('down', 'Scroll down');
    }
    return $hints;
  }

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
  /** @var list<SummonCutsceneDefinition> Discovered definitions, before per-character filtering. */
  private array $availableSummons = [];
  /**
   * @var int The active list index.
   */
  protected int $activeIndex = 0;
  /**
   * @var bool Whether the codex detail view is open for the active summon.
   */
  protected bool $viewingDetail = false;
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
  public static function loadSummons(?GameState $gameState = null, ?Party $party = null): array
  {
    return array_values(array_filter(
      (new SummonCutsceneLibrary())->load(),
      static fn(SummonCutsceneDefinition $definition): bool =>
        $definition->availability === null
        || ($gameState !== null && $definition->isAvailable($gameState, $party)),
    ));
  }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    Console::clear();
    $this->resetMenuPresentation();
    $this->detailViewport = new TextViewport();
    $this->lastDetailPage = null;
    $this->getGameScene()->locationHUDWindow->deactivate();
    $this->character ??= $this->getGameScene()->party->leader;
    $this->availableSummons = self::loadSummons($this->getGameScene()->gameState, $this->party);
    $this->activeIndex = 0;
    $this->viewingDetail = false;
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
      Vocabulary::getTerm('command.' . BattleCommandType::SUMMON->value, 'Summons'),
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
      '',
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
    $this->refreshSummonsForCharacter();
    $this->detailViewport ??= new TextViewport();
    $this->detailViewport->setText($this->getDetailText());
    $this->refreshSummaryPanel();

    if ($this->viewingDetail) {
      $this->refreshDetailPanel();
    } else {
      $this->refreshListPanel();
    }

    $this->refreshInfoPanel();
  }

  /** Apply the party service’s current assignment rules before either renderer sees options. */
  private function refreshSummonsForCharacter(): void
  {
    $selectedId = $this->summons[$this->activeIndex]->id ?? null;
    if ($this->character !== null && !$this->party->members->contains($this->character)) {
      $this->character = $this->party->leader;
    }
    $this->summons = $this->character === null ? [] : array_values(array_filter($this->availableSummons,
      fn(SummonCutsceneDefinition $definition): bool => $definition->wielders === null
        || $this->party->canAssignSummon($definition, $this->character, $this->getGameScene()->gameState)));
    $this->activeIndex = array_find_key($this->summons,
      static fn(SummonCutsceneDefinition $definition): bool => $definition->id === $selectedId) ?? 0;
    if ($this->summons === []) { $this->viewingDetail = false; }
  }

  /** Complete contract text shared by graphical and Terminal reading viewports. */
  protected function getDetailText(): string
  {
    $definition = $this->summons[$this->activeIndex] ?? null;

    if ($definition === null) {
      return "";
    }

    $content = [];

    $header = sprintf(' %s', $definition->name);

    if ($definition->element !== '') {
      $header .= sprintf('  |  %s', $definition->element);
    }

    $content[] = $header;
    $content[] = '';
    $content[] = '';

    $loreText = $definition->lore !== '' ? $definition->lore : $definition->description;

    $content[] = $loreText;

    $content[] = '';

    $labels = array_merge(['Move', 'Element', 'Strong vs', 'Weak vs'], array_map('strval', array_keys($definition->attributes)));
    $labelWidth = max(array_map(TerminalText::displayWidth(...), $labels));
    $row = static fn(string $label, string $value): string =>
      sprintf(' %s : %s', TerminalText::padRight($label, $labelWidth), $value);

    if ($definition->moveName !== null) {
      $content[] = $row('Move', sprintf('%s: %s', $definition->moveName, $definition->description));
    }

    if ($definition->element !== '') {
      $content[] = $row('Element', $definition->element);
    }

    foreach ($definition->attributes as $attribute => $value) {
      $content[] = $row((string)$attribute, (string)$value);
    }

    if (! empty($definition->strengths)) {
      $content[] = $row('Strong vs', implode(', ', $definition->strengths));
    }

    if (! empty($definition->weaknesses)) {
      $content[] = $row('Weak vs', implode(', ', $definition->weaknesses));
    }

    $content[] = '';

    if ($this->character?->hasSummon($definition->id)) { $content[] = 'Assigned'; }

    return implode("\n", $content);
  }

  protected function refreshDetailPanel(): void
  {
    $page = $this->detailViewport->page(self::MENU_WIDTH - 6, self::LIST_PANEL_HEIGHT - 2);
    $this->listPanel->setContent($page->lines);
    $this->listPanel->setHelp($page->total > $page->rows ? $page->range() : '');
    $this->listPanel->render();
    $this->lastDetailPage = $page;
  }

  /** @return list<string> Current character and contract tally for both renderers. */
  protected function getSummaryLines(): array
  {
    if (! $this->character) {
      return [];
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
      ? sprintf(' %s: %d', Vocabulary::getTerm('command.' . BattleCommandType::SUMMON->value, 'Summons'), count($this->summons))
      : sprintf(' Assigned: %d / %d', $assignedCount, count($policySummons));

    return [
      ' ' . $this->character->name,
      sprintf(' Role: %s', $this->character->role->name),
      $tallyLine,
    ];
  }

  protected function refreshSummaryPanel(): void
  {
    $this->summaryPanel->setContent($this->getSummaryLines());
    $this->summaryPanel->render();
  }

  /**
   * Redraws the summon list panel.
   *
   * @return void
   */
  protected function refreshListPanel(): void
  {
    $this->listPanel->setHelp('');
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

    $help = array_map(static fn($hint) => ($hint->control?->label ?? 'Unbound') . ':' . $hint->label, $this->getActionHints());
    $this->infoPanel->setHelp(implode('  ', $help));
    $this->infoPanel->setContent([
      ' ' . trim($definition?->description ?? ''),
      ' ' . ($definition !== null && $this->character?->hasSummon($definition->id) ? 'Assigned' : ''),
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

    return $this->character->hasSummon($definition->id) ? '[Assigned]' : '';
  }

  /**
   * Handles list navigation.
   *
   * @return void
   */
  protected function handleNavigation(): void
  {
    $v = Input::getAxis(AxisName::VERTICAL);
    if ($this->viewingDetail) {
      if (abs($v) > 0 && $this->lastDetailPage !== null) {
        $this->detailViewport->scroll($v > 0 ? 1 : -1, $this->lastDetailPage);
        $this->refreshDetailPanel();
      }
      return;
    }


    if (abs($v) > 0 && count($this->summons) > 0) {
      $this->activeIndex = wrap(
        $this->activeIndex + ($v > 0 ? 1 : -1),
        0,
        count($this->summons) - 1
      );
      $this->detailViewport->setText($this->getDetailText());
      $this->lastDetailPage = null;
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
      if ($this->viewingDetail) {
        $this->viewingDetail = false;
        $this->refreshUI();
        return;
      }

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

    if (! $this->viewingDetail) {
      // First confirm opens the codex entry for the selected summon.
      $this->viewingDetail = true;
      $this->refreshUI();
      return;
    }

    if ($definition->wielders === null) {
      return;
    }

    if ($this->character->hasSummon($definition->id)) {
      $this->party->unassignSummon($definition->id, $this->character);
      alert(sprintf('%s released %s.', $this->character->name, $definition->name));
    } elseif ($this->party->assignSummon($definition, $this->character, $this->getGameScene()->gameState)) {
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
