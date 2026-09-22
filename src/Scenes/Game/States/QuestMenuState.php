<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Core\Menu\MagicMenu\Windows\MagicTabPanel;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Quests\Quest;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasProviderInterface;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Presentation\JournalMenuContent;
use Ichiloto\Engine\UI\Presentation\JournalDocument;
use Ichiloto\Engine\UI\Presentation\JournalSection;
use Ichiloto\Engine\UI\Presentation\JournalMenuPresentation;
use Ichiloto\Engine\UI\Presentation\MenuCanvasState;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\UI\Text\TextPage;
use Ichiloto\Engine\UI\Text\TextViewport;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowHeightPolicy;
use Ichiloto\Engine\UI\Windows\Window;

/** Active/completed lists and a same-panel, fully readable quest journal. */
class QuestMenuState extends GameSceneState implements CanvasProviderInterface
{
  use MenuCanvasState;

  protected const int MENU_WIDTH = 110;
  protected const int SUMMARY_PANEL_HEIGHT = 4;
  protected const int LIST_PANEL_HEIGHT = 27;
  protected const int INFO_PANEL_HEIGHT = 4;
  protected bool $showingCompleted = false;
  /** @var list<Quest> */
  protected array $quests = [];
  protected int $activeIndex = 0;
  protected bool $viewingDetail = false;
  protected ?Window $summaryPanel = null;
  protected ?Window $listPanel = null;
  protected ?Window $infoPanel = null;
  private TextViewport $reading;
  private string $readingKey = '';
  private ?TextPage $displayedPage = null;

  public function enter(): void
  {
    Console::clear();
    $this->getGameScene()->locationHUDWindow->deactivate();
    $this->resetMenuPresentation();
    $this->reading = new TextViewport();
    $this->readingKey = '';
    $this->displayedPage = null;
    $this->showingCompleted = false;
    $this->activeIndex = 0;
    $this->viewingDetail = false;
    $this->reloadQuests();
    $this->initializeUI();
    $this->refreshUI();
  }

  public function execute(?SceneStateContext $context = null): void
  {
    $this->syncReading($this->getPresentationContent());
    // One semantic edge owns this tick, including bindings shared with navigation.
    if (Input::isButtonDown('back') || Input::isButtonDown('cancel')) {
      if ($this->viewingDetail) {
        $this->viewingDetail = false;
        $this->reading->reset();
        $this->refreshUI();
      } else { $this->setState($this->getGameScene()->mainMenuState); }
      return;
    }
    if (!$this->viewingDetail && ($this->isNextCharacterRequested() || $this->isPreviousCharacterRequested())) {
      $this->showingCompleted = !$this->showingCompleted;
      $this->quests = [];
      $this->activeIndex = 0;
      $this->reloadQuests();
      $this->refreshUI();
      return;
    }
    if (Input::isButtonDown('confirm')) {
      if (!$this->viewingDetail && isset($this->quests[$this->activeIndex])) {
        $this->viewingDetail = true;
        $this->reading->reset();
        $this->refreshUI();
      }
      return;
    }
    $vertical = Input::getAxis(AxisName::VERTICAL);
    if (abs($vertical) === 0.0 || $this->quests === []) { return; }
    if ($this->viewingDetail && $this->displayedPage !== null) {
      $this->reading->scroll($vertical > 0 ? 1 : -1, $this->displayedPage);
    } else { $this->activeIndex = wrap($this->activeIndex + ($vertical > 0 ? 1 : -1), 0, count($this->quests) - 1); }
    $this->refreshUI();
  }

  protected function reloadQuests(): void
  {
    $oldId = $this->quests[$this->activeIndex]->id ?? null;
    $manager = $this->getGameScene()->questManager;
    $this->quests = $this->showingCompleted ? ($manager?->completedQuests() ?? []) : ($manager?->activeQuests() ?? []);
    $index = array_find_key($this->quests, fn($quest) => $quest->id === $oldId);
    $this->activeIndex = $index ?? min($this->activeIndex, max(0, count($this->quests) - 1));
    if ($this->quests === []) { $this->viewingDetail = false; }
  }

  public function getPresentationContent(): JournalMenuContent
  {
    $manager = $this->getGameScene()->questManager;
    $rows = [];
    foreach ($this->quests as $quest) {
      $rows[] = ['label' => $quest->name !== '' ? $quest->name : $quest->id,
        'values' => [$quest->giver !== '' ? $quest->giver : '-', $this->describeOverallProgress($quest)],
        'icon' => $this->showingCompleted ? 'quest.complete' : ($quest->isOptional ? 'quest.optional' : 'quest.entry')];
    }
    $quest = $this->quests[$this->activeIndex] ?? null;
    $sections = [];
    if ($quest !== null) {
      $identity = [['text' => 'Giver: ' . ($quest->giver !== '' ? $quest->giver : '-')],
        ['text' => $this->describeOverallProgress($quest)]];
      if ($quest->isOptional) { $identity[] = ['text' => 'Side Quest']; }
      $sections[] = new JournalSection($quest->name !== '' ? $quest->name : $quest->id, $identity, $rows[$this->activeIndex]['icon']);
      $sections[] = new JournalSection('Description', [['text' => $quest->description]], 'journal.description');
      $objectives = [];
      foreach ($quest->objectives as $index => $objective) {
        $progress = $manager?->getObjectiveProgress($quest, $index) ?? 0;
        $complete = $progress >= $objective->quantity;
        $objectives[] = ['text' => sprintf('%s %s (%d/%d)', $complete ? '[Complete]' : '[Open]',
          $manager?->describeObjective($objective) ?? $objective->description, $progress, $objective->quantity),
          'icon' => $complete ? 'objective.complete' : 'objective.open', 'color' => $complete ? 'increase' : 'text'];
      }
      $sections[] = new JournalSection('Objectives', $objectives, 'journal.objectives');
      if (($rewards = $quest->describeRewards()) !== '') {
        $sections[] = new JournalSection('Rewards', [['text' => 'Reward: ' . $rewards]], 'journal.rewards');
      }
    }
    $document = new JournalDocument($sections);
    return new JournalMenuContent('Quests', ['Active (' . count($manager?->activeQuests() ?? []) . ')',
      'Completed (' . count($manager?->completedQuests() ?? []) . ')'], $this->showingCompleted ? 1 : 0, '', $rows,
      $this->activeIndex, $this->showingCompleted ? 'No completed quests.' : 'No active quests.',
      $quest?->id ?? '', $document->text, $quest?->description ?? '', $this->viewingDetail, true, $document);
  }

  /** Reading a different renderer's geometry cannot navigate or reset the owner. */
  public function getPresentationPage(int $columns, int $rows): TextPage
  {
    $content = $this->getPresentationContent();
    $reading = clone $this->reading;
    if ($content->entryKey !== $this->readingKey) { $reading->reset(); }
    $reading->setText($content->detailText);
    return $reading->page($columns, $rows);
  }

  protected function composeMenuCanvas(MenuPresentationCatalog $theme, float $time): ?PresentationCanvas
  {
    $content = $this->getPresentationContent();
    $page = $this->getPresentationPage(...JournalMenuPresentation::pageSize($content, $theme));
    $canvas = JournalMenuPresentation::compose($content, $theme, $page, $time);
    $this->displayedPage = $page;
    return $canvas;
  }

  private function syncReading(JournalMenuContent $content): void
  {
    if ($content->entryKey !== $this->readingKey) { $this->reading->reset(); }
    $this->readingKey = $content->entryKey;
    $this->reading->setText($content->detailText);
  }

  protected function initializeUI(): void
  {
    $width = min(self::MENU_WIDTH, max(20, get_screen_width()));
    $left = max(0, intdiv(get_screen_width() - $width, 2));
    $height = self::SUMMARY_PANEL_HEIGHT + self::LIST_PANEL_HEIGHT + self::INFO_PANEL_HEIGHT;
    $top = max(0, intdiv(get_screen_height() - $height, 2));
    $border = new DefaultBorderPack();
    $this->summaryPanel = new MagicTabPanel('Quests', '', new Vector2($left, $top), $width, self::SUMMARY_PANEL_HEIGHT, $border, heightPolicy: WindowHeightPolicy::FIXED);
    $this->listPanel = new Window('', '', new Vector2($left, $top + self::SUMMARY_PANEL_HEIGHT), $width, self::LIST_PANEL_HEIGHT, $border, heightPolicy: WindowHeightPolicy::FIXED);
    $this->infoPanel = new Window('Info', '', new Vector2($left, $top + self::SUMMARY_PANEL_HEIGHT + self::LIST_PANEL_HEIGHT), $width, self::INFO_PANEL_HEIGHT, $border, heightPolicy: WindowHeightPolicy::FIXED);
  }

  protected function refreshUI(): void
  {
    $content = $this->getPresentationContent();
    $this->syncReading($content);
    $width = $this->listPanel->getContentWidth() - 2;
    $capacity = $this->listPanel->getContentHeight();
    assert($this->summaryPanel instanceof MagicTabPanel);
    $this->summaryPanel->setTabs($content->tabs, $content->tabIndex);
    $this->summaryPanel->setContent([...$this->summaryPanel->getContent(), $content->summary]);
    $page = $this->reading->page($width, $capacity);
    $this->displayedPage = $page;
    if ($this->viewingDetail) {
      $lines = $page->lines;
      $this->listPanel->setHelp($page->range());
    }
    else {
      $first = max(0, min($this->activeIndex - $capacity + 1, count($content->rows) - $capacity));
      $lines = [];
      foreach (array_slice($content->rows, $first, $capacity, true) as $i => $row) {
        $line = ($i === $this->activeIndex ? '> ' : '  ') . TextViewport::preview(implode(' | ', [$row['label'], ...$row['values']]), $width);
        $lines[] = $i === $this->activeIndex ? SelectionStyle::apply($line) : $line;
      }
      $this->listPanel->setHelp($content->rows === [] ? '' : sprintf('%d-%d / %d', $first + 1, $first + count($lines), count($content->rows)));
      if ($content->rows === []) { $lines[] = $content->emptyText; }
    }
    $this->listPanel->setContent(array_pad($lines, $capacity, ''));
    $this->infoPanel->setContent([$content->rows === [] || $this->viewingDetail ? '' : 'View details',
      $this->viewingDetail ? '' : TextViewport::preview($content->previewText, $width)]);
    $this->summaryPanel->render();
    $this->listPanel->render();
    $this->infoPanel->render();
  }

  protected function describeOverallProgress(Quest $quest): string
  {
    if ($this->showingCompleted) { return '[Complete]'; }
    $manager = $this->getGameScene()->questManager;
    $satisfied = 0;
    foreach ($quest->objectives as $index => $objective) {
      if (($manager?->getObjectiveProgress($quest, $index) ?? 0) >= $objective->quantity) { $satisfied++; }
    }
    return sprintf('[%d/%d]', $satisfied, count($quest->objectives));
  }

  public function resume(): void
  {
    $this->reloadQuests();
    $this->refreshUI();
  }
}
