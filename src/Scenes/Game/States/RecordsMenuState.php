<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Progress\Achievement;
use Ichiloto\Engine\Progress\AchievementManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Stores\EnemyStore;
use Ichiloto\Engine\Util\Config\ConfigStore;

/**
 * The player's records: achievements and the bestiary.
 *
 * Two tabs (tab switches) over the same list/detail shape the summon codex
 * uses. Undiscovered bestiary entries stay masked, and secret achievements
 * hide their name and description until earned.
 *
 * @package Ichiloto\Engine\Scenes\Game\States
 */
class RecordsMenuState extends GameSceneState
{
  protected const int MENU_WIDTH = 110;
  protected const int SUMMARY_PANEL_HEIGHT = 4;
  protected const int LIST_PANEL_HEIGHT = 27;
  protected const int INFO_PANEL_HEIGHT = 4;
  protected const string TAB_ACHIEVEMENTS = 'achievements';
  protected const string TAB_BESTIARY = 'bestiary';

  /**
   * @var string The visible tab.
   */
  protected string $tab = self::TAB_ACHIEVEMENTS;
  /**
   * @var array<int, Achievement|Enemy> The rows on the visible tab.
   */
  protected array $rows = [];
  /**
   * @var int The active list index.
   */
  protected int $activeIndex = 0;
  /**
   * @var int The scroll offset.
   */
  protected int $scrollOffset = 0;
  protected int $leftMargin = 0;
  protected int $topMargin = 0;
  protected ?BorderPackInterface $borderPack = null;
  protected ?Window $summaryPanel = null;
  protected ?Window $listPanel = null;
  protected ?Window $infoPanel = null;

  /**
   * Determines whether the project has anything to show here.
   *
   * @return bool True when achievements or enemies are authored.
   */
  public static function projectHasRecords(): bool
  {
    return AchievementManager::projectHasAchievements() || ! empty(self::loadEnemies());
  }

  /**
   * Loads the project's enemies for the bestiary.
   *
   * @return Enemy[] The authored enemies.
   */
  protected static function loadEnemies(): array
  {
    $store = ConfigStore::get(EnemyStore::class);

    if (! $store instanceof EnemyStore) {
      return [];
    }

    return array_values(array_filter($store->all(), static fn(mixed $enemy): bool => $enemy instanceof Enemy));
  }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    Console::clear();
    $this->getGameScene()->locationHUDWindow->deactivate();
    $this->tab = AchievementManager::projectHasAchievements() ? self::TAB_ACHIEVEMENTS : self::TAB_BESTIARY;
    $this->activeIndex = 0;
    $this->scrollOffset = 0;
    $this->reloadRows();
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

    if (Input::isButtonDown('back') || Input::isButtonDown('cancel')) {
      $this->setState($this->getGameScene()->mainMenuState);
    }
  }

  /**
   * Reloads the visible tab's rows.
   *
   * @return void
   */
  protected function reloadRows(): void
  {
    $this->rows = $this->tab === self::TAB_ACHIEVEMENTS
      ? array_values($this->getGameScene()->achievementManager?->achievements ?? [])
      : self::loadEnemies();
    $this->activeIndex = min($this->activeIndex, max(0, count($this->rows) - 1));
    $this->scrollOffset = 0;
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
      'Records',
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
      'tab:Tab  c:Cancel',
      new Vector2($this->leftMargin, $this->topMargin + self::SUMMARY_PANEL_HEIGHT + self::LIST_PANEL_HEIGHT),
      self::MENU_WIDTH,
      self::INFO_PANEL_HEIGHT,
      $this->borderPack
    );
  }

  /**
   * Redraws every panel.
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
   * Redraws the tab/progress summary.
   *
   * @return void
   */
  protected function refreshSummaryPanel(): void
  {
    $achievementManager = $this->getGameScene()->achievementManager;
    $bestiary = $this->getGameScene()->bestiary;
    $totalAchievements = count($achievementManager?->achievements ?? []);
    $unlockedAchievements = count($achievementManager?->unlocked ?? []);
    $totalEnemies = count(self::loadEnemies());

    $achievementsTab = sprintf(
      '%s Achievements (%d/%d)',
      $this->tab === self::TAB_ACHIEVEMENTS ? '▶' : ' ',
      $unlockedAchievements,
      $totalAchievements
    );
    $bestiaryTab = sprintf(
      '%s Bestiary (%d/%d)',
      $this->tab === self::TAB_BESTIARY ? '▶' : ' ',
      $bestiary->discoveredCount(),
      $totalEnemies
    );

    $this->summaryPanel->setContent([
      sprintf(' %s    %s', $achievementsTab, $bestiaryTab),
      sprintf(' Points: %d', $achievementManager?->earnedPoints() ?? 0),
    ]);
    $this->summaryPanel->render();
  }

  /**
   * Redraws the list.
   *
   * @return void
   */
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

    if (empty($this->rows)) {
      $content[] = $this->tab === self::TAB_ACHIEVEMENTS ? ' No achievements authored.' : ' No enemies authored.';
    }

    foreach (array_slice($this->rows, $this->scrollOffset, $visibleRows, true) as $index => $row) {
      $prefix = $index === $this->activeIndex ? '>' : ' ';
      $content[] = $row instanceof Achievement
        ? $this->formatAchievementRow($prefix, $row)
        : $this->formatBestiaryRow($prefix, $row);
    }

    $content = array_pad($content, $visibleRows, '');
    $this->listPanel->setContent($content);
    $this->listPanel->render();
  }

  /**
   * Formats one achievement row.
   *
   * @param string $prefix The selection prefix.
   * @param Achievement $achievement The achievement.
   * @return string The rendered row.
   */
  protected function formatAchievementRow(string $prefix, Achievement $achievement): string
  {
    $isUnlocked = $this->getGameScene()->achievementManager?->isUnlocked($achievement->id) ?? false;
    $isHidden = $achievement->isSecret && ! $isUnlocked;

    return sprintf(
      ' %s %s %s %s',
      $prefix,
      $isUnlocked ? '✓' : ' ',
      TerminalText::padRight($isHidden ? '???' : trim($achievement->icon . ' ' . $achievement->name), 34),
      $isHidden ? 'Secret achievement' : $achievement->description
    );
  }

  /**
   * Formats one bestiary row, masking undiscovered enemies.
   *
   * @param string $prefix The selection prefix.
   * @param Enemy $enemy The enemy.
   * @return string The rendered row.
   */
  protected function formatBestiaryRow(string $prefix, Enemy $enemy): string
  {
    $bestiary = $this->getGameScene()->bestiary;

    if (! $bestiary->hasSeen($enemy->name)) {
      return sprintf(' %s   %s', $prefix, TerminalText::padRight('??????', 34));
    }

    return sprintf(
      ' %s   %s Lv %-4d  seen %-4d  defeated %-4d',
      $prefix,
      TerminalText::padRight($enemy->name, 34),
      $enemy->level,
      $bestiary->timesSeen($enemy->name),
      $bestiary->timesDefeated($enemy->name)
    );
  }

  /**
   * Redraws the detail line for the active row.
   *
   * @return void
   */
  protected function refreshInfoPanel(): void
  {
    $row = $this->rows[$this->activeIndex] ?? null;
    $lines = ['', ''];

    if ($row instanceof Achievement) {
      $isUnlocked = $this->getGameScene()->achievementManager?->isUnlocked($row->id) ?? false;
      $lines[0] = $row->isSecret && ! $isUnlocked
        ? ' A secret achievement. Earn it to reveal what it was.'
        : ' ' . $row->description;
      $lines[1] = $isUnlocked ? ' Unlocked' : ' Locked';
    } elseif ($row instanceof Enemy) {
      $bestiary = $this->getGameScene()->bestiary;
      $lines[0] = $bestiary->hasSeen($row->name)
        ? sprintf(' HP %d   Attack %d   Defence %d', $row->stats->totalHp, $row->stats->attack, $row->stats->defence)
        : ' Not yet encountered.';
      $lines[1] = $bestiary->timesDefeated($row->name) > 0
        ? sprintf(' Rewards: %d EXP, %d G', $row->rewards->experience, $row->rewards->gold)
        : '';
    }

    $this->infoPanel->setContent($lines);
    $this->infoPanel->render();
  }

  /**
   * Handles tab switching.
   *
   * @return bool True when the tab changed.
   */
  protected function handleTabSwitching(): bool
  {
    if (! $this->isNextCharacterRequested() && ! $this->isPreviousCharacterRequested()) {
      return false;
    }

    $this->tab = $this->tab === self::TAB_ACHIEVEMENTS ? self::TAB_BESTIARY : self::TAB_ACHIEVEMENTS;
    $this->activeIndex = 0;
    $this->reloadRows();
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
    $v = Input::getAxis(AxisName::VERTICAL);

    if (abs($v) > 0 && count($this->rows) > 0) {
      $this->activeIndex = wrap($this->activeIndex + ($v > 0 ? 1 : -1), 0, count($this->rows) - 1);
      $this->refreshListPanel();
      $this->refreshInfoPanel();
    }
  }

  /**
   * @inheritDoc
   */
  public function resume(): void
  {
    $this->reloadRows();
    $this->refreshUI();
  }
}
