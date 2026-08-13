<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Quests\Quest;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Displays the party's quest journal.
 *
 * Two tabs — active and completed — list the party's quests; opening an
 * entry shows the full journal page with the giver, description, each
 * objective's progress, and the rewards.
 *
 * @package Ichiloto\Engine\Scenes\Game\States
 */
class QuestMenuState extends GameSceneState
{
  protected const int MENU_WIDTH = 110;
  protected const int SUMMARY_PANEL_HEIGHT = 4;
  protected const int LIST_PANEL_HEIGHT = 27;
  protected const int INFO_PANEL_HEIGHT = 4;

  /**
   * @var bool True when the completed tab is shown.
   */
  protected bool $showingCompleted = false;
  /**
   * @var Quest[] The quests on the visible tab.
   */
  protected array $quests = [];
  /**
   * @var int The active list index.
   */
  protected int $activeIndex = 0;
  /**
   * @var bool Whether the journal detail view is open for the active quest.
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
   * @var Window|null The tab summary panel.
   */
  protected ?Window $summaryPanel = null;
  /**
   * @var Window|null The quest list panel.
   */
  protected ?Window $listPanel = null;
  /**
   * @var Window|null The bottom description panel.
   */
  protected ?Window $infoPanel = null;

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    Console::clear();
    $this->getGameScene()->locationHUDWindow->deactivate();
    $this->showingCompleted = false;
    $this->activeIndex = 0;
    $this->viewingDetail = false;
    $this->reloadQuests();
    $this->calculateMargins();
    $this->initializeUI();
    $this->refreshUI();
  }

  /**
   * @inheritDoc
   */
  public function execute(?SceneStateContext $context = null): void
  {
    if ($this->handleTabSwitching()) {
      return;
    }

    $this->handleNavigation();
    $this->handleActions();
  }

  /**
   * Reloads the visible tab's quests.
   *
   * @return void
   */
  protected function reloadQuests(): void
  {
    $questManager = $this->getGameScene()->questManager;

    $this->quests = $this->showingCompleted
      ? ($questManager?->completedQuests() ?? [])
      : ($questManager?->activeQuests() ?? []);
    $this->activeIndex = min($this->activeIndex, max(0, count($this->quests) - 1));
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
      'Quests',
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
      'enter:Details  tab:Tab  c:Cancel',
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

    if ($this->viewingDetail) {
      $this->refreshDetailPanel();
    } else {
      $this->refreshListPanel();
    }

    $this->refreshInfoPanel();
  }

  /**
   * Redraws the tab summary panel.
   *
   * @return void
   */
  protected function refreshSummaryPanel(): void
  {
    $questManager = $this->getGameScene()->questManager;
    $activeCount = count($questManager?->activeQuests() ?? []);
    $completedCount = count($questManager?->completedQuests() ?? []);

    $activeTab = sprintf('%s Active (%d)', $this->showingCompleted ? ' ' : '▶', $activeCount);
    $completedTab = sprintf('%s Completed (%d)', $this->showingCompleted ? '▶' : ' ', $completedCount);

    $this->summaryPanel->setContent([
      sprintf(' %s    %s', $activeTab, $completedTab),
      ' Use the arrow keys to select a quest.',
    ]);
    $this->summaryPanel->render();
  }

  /**
   * Redraws the quest list panel.
   *
   * @return void
   */
  protected function refreshListPanel(): void
  {
    $content = [];

    if (empty($this->quests)) {
      $content[] = $this->showingCompleted
        ? ' No completed quests.'
        : ' No active quests.';
    }

    foreach ($this->quests as $index => $quest) {
      $prefix = $index === $this->activeIndex ? '>' : ' ';
      $name = TerminalText::padRight($quest->name, 40);
      $giver = TerminalText::padRight($quest->giver !== '' ? $quest->giver : '-', 24);
      $content[] = sprintf(' %s %s %s %s', $prefix, $name, $giver, $this->describeOverallProgress($quest));
    }

    $content = array_pad($content, self::LIST_PANEL_HEIGHT - 2, '');
    $this->listPanel->setContent($content);
    $this->listPanel->render();
  }

  /**
   * Redraws the journal page for the active quest.
   *
   * @return void
   */
  protected function refreshDetailPanel(): void
  {
    $quest = $this->quests[$this->activeIndex] ?? null;

    if ($quest === null) {
      return;
    }

    $questManager = $this->getGameScene()->questManager;
    $innerWidth = self::MENU_WIDTH - 6;
    $content = [];

    $header = sprintf(' %s', $quest->name);

    if ($quest->giver !== '') {
      $header .= sprintf('  |  from %s', $quest->giver);
    }

    if ($quest->isOptional) {
      $header .= '  |  Side Quest';
    }

    $content[] = $header;
    $content[] = ' ' . str_repeat('─', $innerWidth);
    $content[] = '';

    foreach (explode("\n", wordwrap($quest->description, $innerWidth, "\n", true)) as $line) {
      $content[] = ' ' . $line;
    }

    $content[] = '';
    $content[] = ' Objectives';

    foreach ($quest->objectives as $index => $objective) {
      $progress = $questManager?->getObjectiveProgress($quest, $index) ?? 0;
      $mark = $progress >= $objective->quantity ? '✓' : '·';
      $count = $objective->quantity > 1 ? sprintf(' (%d/%d)', $progress, $objective->quantity) : '';
      $content[] = sprintf(
        '   %s %s%s',
        $mark,
        $questManager?->describeObjective($objective) ?? $objective->description,
        $count,
      );
    }

    if (($rewards = $quest->describeRewards()) !== '') {
      $content[] = '';
      $content[] = sprintf(' Reward: %s', $rewards);
    }

    $content = array_pad($content, self::LIST_PANEL_HEIGHT - 2, '');
    $this->listPanel->setContent(array_slice($content, 0, self::LIST_PANEL_HEIGHT - 2));
    $this->listPanel->render();
  }

  /**
   * Redraws the bottom description panel.
   *
   * @return void
   */
  protected function refreshInfoPanel(): void
  {
    $quest = $this->quests[$this->activeIndex] ?? null;

    if ($this->viewingDetail) {
      $this->infoPanel->setHelp('c:Back');
      $this->infoPanel->setContent([
        ' ' . ($quest?->name ?? ''),
        '',
      ]);
    } else {
      $this->infoPanel->setHelp('enter:Details  tab:Tab  c:Cancel');
      $firstLine = strtok($quest?->description ?? '', "\n");
      $this->infoPanel->setContent([
        ' ' . trim($firstLine !== false ? $firstLine : ''),
        '',
      ]);
    }

    $this->infoPanel->render();
  }

  /**
   * Summarizes a quest's overall objective progress.
   *
   * @param Quest $quest The quest.
   * @return string The progress label.
   */
  protected function describeOverallProgress(Quest $quest): string
  {
    if ($this->showingCompleted) {
      return '[Complete]';
    }

    $questManager = $this->getGameScene()->questManager;
    $satisfied = 0;

    foreach ($quest->objectives as $index => $objective) {
      if (($questManager?->getObjectiveProgress($quest, $index) ?? 0) >= $objective->quantity) {
        $satisfied++;
      }
    }

    return sprintf('[%d/%d]', $satisfied, count($quest->objectives));
  }

  /**
   * Handles switching between the active and completed tabs.
   *
   * @return bool True when the tab changed.
   */
  protected function handleTabSwitching(): bool
  {
    if ($this->viewingDetail) {
      return false;
    }

    if (! $this->isNextCharacterRequested() && ! $this->isPreviousCharacterRequested()) {
      return false;
    }

    $this->showingCompleted = ! $this->showingCompleted;
    $this->activeIndex = 0;
    $this->reloadQuests();
    $this->refreshUI();

    return true;
  }

  /**
   * Handles list navigation.
   *
   * @return void
   */
  protected function handleNavigation(): void
  {
    if ($this->viewingDetail) {
      return;
    }

    $v = Input::getAxis(AxisName::VERTICAL);

    if (abs($v) > 0 && count($this->quests) > 0) {
      $this->activeIndex = wrap(
        $this->activeIndex + ($v > 0 ? 1 : -1),
        0,
        count($this->quests) - 1
      );
      $this->refreshListPanel();
      $this->refreshInfoPanel();
    }
  }

  /**
   * Handles opening the journal page and leaving the screen.
   *
   * @return void
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

    if (! $this->viewingDetail && isset($this->quests[$this->activeIndex])) {
      $this->viewingDetail = true;
      $this->refreshUI();
    }
  }

  /**
   * @inheritDoc
   */
  public function resume(): void
  {
    $this->reloadQuests();
    $this->refreshUI();
  }
}
