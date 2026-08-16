<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Progress\Achievement;
use Ichiloto\Engine\Progress\AchievementManager;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeCatalog;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeDepth;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeSubject;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Config\ConfigStore;

/** Achievements and the project-owned, player-earned Field Index. */
class RecordsMenuState extends GameSceneState
{
  protected const int MENU_WIDTH = 110;
  protected const int SUMMARY_PANEL_HEIGHT = 4;
  protected const int LIST_PANEL_HEIGHT = 27;
  protected const int INFO_PANEL_HEIGHT = 4;
  protected const string TAB_ACHIEVEMENTS = 'achievements';
  protected const string TAB_FIELD_INDEX = 'field_index';

  protected string $tab = self::TAB_ACHIEVEMENTS;
  /** @var array<int, Achievement|KnowledgeSubject> */
  protected array $rows = [];
  protected int $activeIndex = 0;
  protected int $scrollOffset = 0;
  protected int $leftMargin = 0;
  protected int $topMargin = 0;
  protected ?BorderPackInterface $borderPack = null;
  protected ?Window $summaryPanel = null;
  protected ?Window $listPanel = null;
  protected ?Window $infoPanel = null;

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
    $this->tab = AchievementManager::projectHasAchievements() ? self::TAB_ACHIEVEMENTS : self::TAB_FIELD_INDEX;
    $this->activeIndex = 0;
    $this->scrollOffset = 0;
    $this->reloadRows();
    $this->calculateMargins();
    $this->initializeUI();
    $this->refreshUI();
  }

  public function execute(?SceneStateContext $context = null): void
  {
    if ($this->handleTabSwitching()) {
      return;
    }

    $this->handleNavigation();
    if (Input::isButtonDown('back') || Input::isButtonDown('cancel')) {
      $this->setState($this->getGameScene()->mainMenuState);
    }
  }

  protected function reloadRows(): void
  {
    $this->rows = $this->tab === self::TAB_ACHIEVEMENTS
      ? array_values($this->getGameScene()->achievementManager?->achievements ?? [])
      : $this->getGameScene()->knowledge->discoveredSubjects();
    $this->activeIndex = min($this->activeIndex, max(0, count($this->rows) - 1));
    $this->scrollOffset = 0;
  }

  protected function calculateMargins(): void
  {
    $totalHeight = self::SUMMARY_PANEL_HEIGHT + self::LIST_PANEL_HEIGHT + self::INFO_PANEL_HEIGHT;
    $this->leftMargin = max(0, intdiv(get_screen_width() - self::MENU_WIDTH, 2));
    $this->topMargin = max(0, intdiv(get_screen_height() - $totalHeight, 2));
  }

  protected function initializeUI(): void
  {
    $this->borderPack = new DefaultBorderPack();
    $this->summaryPanel = new Window('Records', '', new Vector2($this->leftMargin, $this->topMargin), self::MENU_WIDTH, self::SUMMARY_PANEL_HEIGHT, $this->borderPack);
    $this->listPanel = new Window('', '', new Vector2($this->leftMargin, $this->topMargin + self::SUMMARY_PANEL_HEIGHT), self::MENU_WIDTH, self::LIST_PANEL_HEIGHT, $this->borderPack);
    $this->infoPanel = new Window('Info', 'tab:Tab  c:Cancel', new Vector2($this->leftMargin, $this->topMargin + self::SUMMARY_PANEL_HEIGHT + self::LIST_PANEL_HEIGHT), self::MENU_WIDTH, self::INFO_PANEL_HEIGHT, $this->borderPack);
  }

  protected function refreshUI(): void
  {
    $this->refreshSummaryPanel();
    $this->refreshListPanel();
    $this->refreshInfoPanel();
  }

  protected function refreshSummaryPanel(): void
  {
    $manager = $this->getGameScene()->achievementManager;
    $achievementTab = sprintf(
      '%s Achievements (%d/%d)',
      $this->tab === self::TAB_ACHIEVEMENTS ? '▶' : ' ',
      count($manager?->unlocked ?? []),
      count($manager?->achievements ?? []),
    );
    $fieldIndexTab = sprintf(
      '%s Field Index (%d records)',
      $this->tab === self::TAB_FIELD_INDEX ? '▶' : ' ',
      count($this->getGameScene()->knowledge->discoveredSubjects()),
    );

    $this->summaryPanel->setContent([
      sprintf(' %s    %s', $achievementTab, $fieldIndexTab),
      sprintf(' Points: %d', $manager?->earnedPoints() ?? 0),
    ]);
    $this->summaryPanel->render();
  }

  protected function refreshListPanel(): void
  {
    $visibleRows = self::LIST_PANEL_HEIGHT - 2;
    $this->scrollOffset = max(0, min($this->scrollOffset, max(0, count($this->rows) - $visibleRows)));
    if ($this->activeIndex < $this->scrollOffset) {
      $this->scrollOffset = $this->activeIndex;
    } elseif ($this->activeIndex >= $this->scrollOffset + $visibleRows) {
      $this->scrollOffset = $this->activeIndex - $visibleRows + 1;
    }

    $content = [];
    if ($this->rows === []) {
      $content[] = $this->tab === self::TAB_ACHIEVEMENTS
        ? ' No achievements authored.'
        : ' No field records discovered.';
    }
    foreach (array_slice($this->rows, $this->scrollOffset, $visibleRows, true) as $index => $row) {
      $prefix = $index === $this->activeIndex ? '>' : ' ';
      $content[] = $row instanceof Achievement
        ? $this->formatAchievementRow($prefix, $row)
        : $this->formatKnowledgeRow($prefix, $row);
    }

    $this->listPanel->setContent(array_pad($content, $visibleRows, ''));
    $this->listPanel->render();
  }

  protected function formatAchievementRow(string $prefix, Achievement $achievement): string
  {
    $unlocked = $this->getGameScene()->achievementManager?->isUnlocked($achievement->id) ?? false;
    $hidden = $achievement->isSecret && ! $unlocked;

    return sprintf(
      ' %s %s %s %s',
      $prefix,
      $unlocked ? '✓' : ' ',
      TerminalText::padRight($hidden ? '???' : trim($achievement->icon . ' ' . $achievement->name), 34),
      $hidden ? 'Secret achievement' : $achievement->description,
    );
  }

  protected function formatKnowledgeRow(string $prefix, KnowledgeSubject $subject): string
  {
    $depth = $this->getGameScene()->knowledge->progress->depth($subject->id);
    $identity = $subject->family ?? $subject->species ?? $subject->recordType;

    return sprintf(
      ' %s   %s %-18s  %s',
      $prefix,
      TerminalText::padRight($subject->displayName, 34),
      $identity,
      strtolower($depth->name),
    );
  }

  protected function refreshInfoPanel(): void
  {
    $row = $this->rows[$this->activeIndex] ?? null;
    $lines = ['', ''];
    if ($row instanceof Achievement) {
      $unlocked = $this->getGameScene()->achievementManager?->isUnlocked($row->id) ?? false;
      $lines[0] = $row->isSecret && ! $unlocked ? ' A secret achievement. Earn it to reveal what it was.' : ' ' . $row->description;
      $lines[1] = $unlocked ? ' Unlocked' : ' Locked';
    } elseif ($row instanceof KnowledgeSubject) {
      $depth = $this->getGameScene()->knowledge->progress->depth($row->id);
      $lines[0] = ' ' . ($depth->value >= KnowledgeDepth::OBSERVED->value && $row->deepCard !== null ? $row->deepCard : $row->quickCard);

      foreach ($this->getGameScene()->knowledge->catalog->reportsFor($row->id) as $report) {
        $state = $this->getGameScene()->knowledge->progress->reportState($row->id, $report->id);
        if ($state !== null) {
          $lines[1] = sprintf(' %s: %s', ucfirst(strval($state['status'] ?? 'active')), $report->summary);
          break;
        }
      }
    }

    $this->infoPanel->setContent($lines);
    $this->infoPanel->render();
  }

  protected function handleTabSwitching(): bool
  {
    if (! $this->isNextCharacterRequested() && ! $this->isPreviousCharacterRequested()) {
      return false;
    }
    $this->tab = $this->tab === self::TAB_ACHIEVEMENTS ? self::TAB_FIELD_INDEX : self::TAB_ACHIEVEMENTS;
    $this->activeIndex = 0;
    $this->reloadRows();
    $this->refreshUI();
    return true;
  }

  protected function handleNavigation(): void
  {
    $vertical = Input::getAxis(AxisName::VERTICAL);
    if (abs($vertical) > 0 && $this->rows !== []) {
      $this->activeIndex = wrap($this->activeIndex + ($vertical > 0 ? 1 : -1), 0, count($this->rows) - 1);
      $this->refreshListPanel();
      $this->refreshInfoPanel();
    }
  }

  public function resume(): void
  {
    $this->reloadRows();
    $this->refreshUI();
  }
}
