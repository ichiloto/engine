<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Core\Menu\MagicMenu\Windows\MagicTabPanel;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Progress\Achievement;
use Ichiloto\Engine\Progress\AchievementManager;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeCatalog;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeDepth;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeSubject;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Presentation\JournalMenuContent;
use Ichiloto\Engine\UI\Presentation\JournalMenuPresentation;
use Ichiloto\Engine\UI\Presentation\MenuCanvasState;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\UI\Text\TextPage;
use Ichiloto\Engine\UI\Text\TextViewport;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowHeightPolicy;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Config\ConfigStore;

/** Achievements and the project-owned, player-earned Field Index. */
class RecordsMenuState extends GameSceneState implements CanvasProviderInterface
{
  use MenuCanvasState;

  protected const int MENU_WIDTH = 110;
  protected const int SUMMARY_PANEL_HEIGHT = 4;
  protected const int LIST_PANEL_HEIGHT = 23;
  protected const int INFO_PANEL_HEIGHT = 8;
  protected const string TAB_ACHIEVEMENTS = 'achievements';
  protected const string TAB_FIELD_INDEX = 'field_index';
  protected string $tab = self::TAB_ACHIEVEMENTS;
  /** @var list<Achievement|KnowledgeSubject> */
  protected array $rows = [];
  protected int $activeIndex = 0;
  protected bool $viewingDetail = false;
  protected ?Window $summaryPanel = null;
  protected ?Window $listPanel = null;
  protected ?Window $infoPanel = null;
  private TextViewport $reading;
  private string $readingKey = '';
  private ?TextPage $displayedPage = null;

  public static function projectHasRecords(): bool
  {
    $catalog = ConfigStore::get(KnowledgeCatalog::class);
    return AchievementManager::projectHasAchievements()
      || ($catalog instanceof KnowledgeCatalog && $catalog->subjects() !== []);
  }

  public function enter(): void
  {
    Console::clear();
    $this->getGameScene()->locationHUDWindow->deactivate();
    $this->resetMenuPresentation();
    $this->reading = new TextViewport();
    $this->readingKey = '';
    $this->displayedPage = null;
    $this->viewingDetail = false;
    $this->tab = AchievementManager::projectHasAchievements() ? self::TAB_ACHIEVEMENTS : self::TAB_FIELD_INDEX;
    $this->activeIndex = 0;
    $this->reloadRows();
    $this->initializeUI();
    $this->refreshUI();
  }

  public function execute(?SceneStateContext $context = null): void
  {
    $this->syncReading($this->getPresentationContent());
    if (Input::isButtonDown('back') || Input::isButtonDown('cancel')) {
      if ($this->viewingDetail) {
        $this->viewingDetail = false;
        $this->reading->reset();
        $this->refreshUI();
      } else { $this->setState($this->getGameScene()->mainMenuState); }
      return;
    }
    if ($this->isNextCharacterRequested() || $this->isPreviousCharacterRequested()) {
      $this->tab = $this->tab === self::TAB_ACHIEVEMENTS ? self::TAB_FIELD_INDEX : self::TAB_ACHIEVEMENTS;
      $this->viewingDetail = false;
      $this->activeIndex = 0;
      $this->rows = [];
      $this->reloadRows();
      $this->refreshUI();
      return;
    }
    if (Input::isButtonDown('confirm')) {
      if (!$this->viewingDetail && $this->rows !== []) {
        $this->viewingDetail = true;
        $this->reading->reset();
        $this->refreshUI();
      }
      return;
    }
    $vertical = Input::getAxis(AxisName::VERTICAL);
    if (abs($vertical) === 0.0 || $this->rows === []) { return; }
    if ($this->viewingDetail && $this->displayedPage !== null) {
      $this->reading->scroll($vertical > 0 ? 1 : -1, $this->displayedPage);
    } else { $this->activeIndex = wrap($this->activeIndex + ($vertical > 0 ? 1 : -1), 0, count($this->rows) - 1); }
    $this->refreshUI();
  }

  protected function reloadRows(): void
  {
    $oldId = $this->rows[$this->activeIndex]->id ?? null;
    $this->rows = $this->tab === self::TAB_ACHIEVEMENTS
      ? array_values($this->getGameScene()->achievementManager?->achievements ?? [])
      : $this->getGameScene()->knowledge->discoveredSubjects();
    $index = array_find_key($this->rows, fn($row) => $row->id === $oldId);
    $this->activeIndex = $index ?? min($this->activeIndex, max(0, count($this->rows) - 1));
    if ($this->rows === []) { $this->viewingDetail = false; }
  }

  public function getPresentationContent(): JournalMenuContent
  {
    $manager = $this->getGameScene()->achievementManager;
    $knowledge = $this->getGameScene()->knowledge;
    $rows = [];
    foreach ($this->rows as $row) {
      if ($row instanceof Achievement) {
        $unlocked = $manager?->isUnlocked($row->id) ?? false;
        $hidden = $row->isSecret && !$unlocked;
        $rows[] = ['label' => $hidden ? '???' : trim($row->icon . ' ' . $row->name),
          'values' => [$unlocked ? 'Unlocked' : 'Locked', $hidden ? 'Secret achievement' : $row->description]];
      } else {
        $rows[] = ['label' => $row->displayName, 'values' => [$row->family ?? $row->species ?? $row->recordType,
          strtolower($knowledge->progress->depth($row->id)->name)]];
      }
    }
    $row = $this->rows[$this->activeIndex] ?? null;
    $lines = [];
    if ($row instanceof Achievement) {
      $unlocked = $manager?->isUnlocked($row->id) ?? false;
      $lines = $row->isSecret && !$unlocked ? ['???', 'A secret achievement. Earn it to reveal what it was.', 'Locked']
        : [trim($row->icon . ' ' . $row->name), $row->description, $unlocked ? 'Unlocked' : 'Locked', 'Points: ' . $row->points];
    } elseif ($row instanceof KnowledgeSubject) {
      $depth = $knowledge->progress->depth($row->id);
      $lines = [$row->displayName, 'Type: ' . $row->recordType];
      if ($row->family !== null) { $lines[] = 'Family: ' . $row->family; }
      if ($row->species !== null) { $lines[] = 'Species: ' . $row->species; }
      array_push($lines, 'Depth: ' . strtolower($depth->name), '',
        $depth->value >= KnowledgeDepth::OBSERVED->value && $row->deepCard !== null ? $row->deepCard : $row->quickCard);
      // Retain the owner's first earned report, including amended/withdrawn status.
      foreach ($knowledge->catalog->reportsFor($row->id) as $report) {
        $state = $knowledge->progress->reportState($row->id, $report->id);
        if ($state === null) { continue; }
        array_push($lines, '', $report->title, ucfirst(strval($state['status'] ?? 'active')) . ': ' . $report->summary);
        if ($report->details !== null) { $lines[] = $report->details; }
        break;
      }
    }
    return new JournalMenuContent('Records', ['Achievements (' . count($manager?->unlocked ?? []) . '/' . count($manager?->achievements ?? []) . ')',
      'Field Index (' . count($knowledge->discoveredSubjects()) . ')'], $this->tab === self::TAB_ACHIEVEMENTS ? 0 : 1,
      'Points: ' . ($manager?->earnedPoints() ?? 0), $rows, $this->activeIndex,
      $this->tab === self::TAB_ACHIEVEMENTS ? 'No achievements authored.' : 'No field records discovered.',
      $row === null ? '' : $this->tab . ':' . $row->id, implode("\n", $lines), '', $this->viewingDetail, false);
  }

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
    $top = max(0, intdiv(get_screen_height() - 35, 2));
    $border = new DefaultBorderPack();
    $this->summaryPanel = new MagicTabPanel('Records', '', new Vector2($left, $top), $width, self::SUMMARY_PANEL_HEIGHT, $border, heightPolicy: WindowHeightPolicy::FIXED);
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
    $first = max(0, min($this->activeIndex - $capacity + 1, count($content->rows) - $capacity));
    $lines = [];
    foreach (array_slice($content->rows, $first, $capacity, true) as $i => $row) {
      $line = ($i === $this->activeIndex ? '> ' : '  ') . TextViewport::preview(implode(' | ', [$row['label'], ...$row['values']]), $width);
      $lines[] = $i === $this->activeIndex ? SelectionStyle::apply($line) : $line;
    }
    $this->listPanel->setHelp($content->rows === [] ? '' : sprintf('%d-%d / %d', $first + 1, $first + count($lines), count($content->rows)));
    if ($content->rows === []) { $lines[] = $content->emptyText; }
    $this->listPanel->setContent(array_pad($lines, $capacity, ''));
    $metadata = !$this->viewingDetail && $content->rows !== [] ? ['View details'] : [];
    $page = $this->reading->page($width, $this->infoPanel->getContentHeight() - count($metadata));
    $this->displayedPage = $page;
    $this->infoPanel->setHelp($this->viewingDetail ? 'Details - ' . $page->range() : '');
    $this->infoPanel->setContent(array_pad([...$metadata, ...$page->lines], $this->infoPanel->getContentHeight(), ''));
    $this->summaryPanel->render();
    $this->listPanel->render();
    $this->infoPanel->render();
  }

  public function resume(): void
  {
    $this->reloadRows();
    $this->refreshUI();
  }
}
