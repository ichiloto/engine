<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Core\Menu\MagicMenu\Windows\MagicTabPanel;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Animations\Timelines\EffectPlaybackTiming;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Cutscenes\Presentation\CinematicStageSession;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\Progression\TutorialDefinition;
use Ichiloto\Engine\Progression\TutorialReplayMode;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\UI\Accessibility;
use Ichiloto\Engine\UI\Presentation\MenuCanvas;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;
use RuntimeException;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
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
use Ichiloto\Engine\UI\Presentation\MenuDetailStyle;
use Ichiloto\Engine\UI\Presentation\MenuDetailViewport;
use Ichiloto\Engine\UI\Presentation\MenuDetailEntry;
use Ichiloto\Engine\UI\Presentation\MenuDetailSheet;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\SummonInfoSheet;
use Ichiloto\Engine\UI\Presentation\EnemyInsightSheet;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Util\Stores\EnemyStore;
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\UI\Text\TextPage;
use Ichiloto\Engine\UI\Text\TextViewport;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowHeightPolicy;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Config\ConfigStore;

/** Achievements, the player-earned Field Index, and encountered guides. */
class RecordsMenuState extends GameSceneState implements CanvasProviderInterface
{
  use MenuCanvasState;

  protected const int MENU_WIDTH = 110;
  protected const int SUMMARY_PANEL_HEIGHT = 4;
  protected const int LIST_PANEL_HEIGHT = 23;
  protected const int INFO_PANEL_HEIGHT = 8;
  protected RecordsMenuTab $tab = RecordsMenuTab::ACHIEVEMENTS;
  /** @var list<Achievement|KnowledgeSubject|TutorialDefinition> */
  protected array $rows = [];
  protected int $activeIndex = 0;
  protected bool $viewingDetail = false;
  protected ?Window $summaryPanel = null;
  protected ?Window $listPanel = null;
  protected ?Window $infoPanel = null;
  private TextViewport $reading;
  private string $readingKey = '';
  private ?TextPage $displayedPage = null;
  private ?CinematicStageSession $demo = null;
  private float $demoElapsed = 0.0;
  /** @var array<string, SummonCutsceneDefinition>|null The project's summons by id, read once the menu shows a record of one. */
  private ?array $summons = null;

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
    $this->summons = null;
    $this->viewingDetail = false;
    $this->tab = match (true) {
      AchievementManager::projectHasAchievements() => RecordsMenuTab::ACHIEVEMENTS,
      $this->getGameScene()->knowledge->catalog->subjects() !== [] => RecordsMenuTab::FIELD_INDEX,
      $this->getGameScene()->tutorialDirector !== null => RecordsMenuTab::GUIDE,
      default => RecordsMenuTab::FIELD_INDEX,
    };
    $this->activeIndex = 0;
    $this->reloadRows();
    $this->initializeUI();
    $this->refreshUI();
  }

  public function execute(?SceneStateContext $context = null): void
  {
    if ($this->demo !== null) {
      $this->demoElapsed += max(0.0, Time::getDeltaTime());
      $this->demo->advanceTo($this->demoElapsed);
      if ($this->demo->hasEnded || $this->demo->hasPresentationFailure || Input::isButtonDown('back') || Input::isButtonDown('cancel')) {
        $this->releaseDemo();
        $this->refreshUI();
      }
      return;
    }

    $this->syncReading($this->getPresentationContent());
    if (Input::isButtonDown('back') || Input::isButtonDown('cancel')) {
      if ($this->viewingDetail) {
        $this->viewingDetail = false;
        $this->reading->reset();
        $this->refreshUI();
      } else { $this->setState($this->getGameScene()->mainMenuState); }
      return;
    }
    $direction = $this->isNextCharacterRequested() ? 1 : ($this->isPreviousCharacterRequested() ? -1 : 0);
    if ($direction !== 0) {
      $tabs = $this->getTabs();
      $index = array_search($this->tab, $tabs, true);
      $this->tab = $tabs[wrap($index + $direction, 0, count($tabs) - 1)];
      $this->viewingDetail = false;
      $this->activeIndex = 0;
      $this->rows = [];
      $this->reloadRows();
      $this->refreshUI();
      return;
    }
    if (Input::isButtonDown('confirm')) {
      $row = $this->rows[$this->activeIndex] ?? null;
      if ($row instanceof TutorialDefinition && !$this->viewingDetail) {
        $this->activateGuide($row);
      } elseif (!$this->viewingDetail && $row !== null) {
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
    $scene = $this->getGameScene();
    $this->rows = match ($this->tab) {
      RecordsMenuTab::ACHIEVEMENTS => array_values($scene->achievementManager?->achievements ?? []),
      RecordsMenuTab::FIELD_INDEX => $scene->knowledge->discoveredSubjects(),
      RecordsMenuTab::GUIDE => $scene->tutorialDirector?->getGuideEntries() ?? [],
    };
    if ($this->tab === RecordsMenuTab::GUIDE) {
      usort($this->rows, static fn(TutorialDefinition $a, TutorialDefinition $b): int => strcasecmp($a->category, $b->category));
    }
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
      } elseif ($row instanceof TutorialDefinition) {
        $rows[] = ['label' => $row->title, 'values' => [$row->category]];
      } else {
        $rows[] = ['label' => $row->displayName, 'values' => [$row->family ?? $row->species ?? $this->getRecordTypeName($row),
          strtolower($knowledge->progress->depth($row->id)->name)]];
      }
    }
    $row = $this->rows[$this->activeIndex] ?? null;
    $sections = [];
    if ($row instanceof Achievement) {
      $unlocked = $manager?->isUnlocked($row->id) ?? false;
      $sections[] = $row->isSecret && !$unlocked
        ? [MenuDetailEntry::createProse('???'), MenuDetailEntry::createProse('A secret achievement. Earn it to reveal what it was.'),
          MenuDetailEntry::createProse('Locked')]
        : [...self::createProse(trim($row->icon . ' ' . $row->name)), ...self::createProse($row->description),
          MenuDetailEntry::createProse($unlocked ? 'Unlocked' : 'Locked'), MenuDetailEntry::createField('Points', (string)$row->points)];
    } elseif ($row instanceof KnowledgeSubject) {
      $depth = $knowledge->progress->depth($row->id);
      $sections = [[...self::createProse($row->displayName), ...self::createField('Type', $this->getRecordTypeName($row)),
        ...self::createField('Family', $row->family ?? ''), ...self::createField('Species', $row->species ?? ''),
        MenuDetailEntry::createField('Depth', strtolower($depth->name))],
        self::createProse($depth->value >= KnowledgeDepth::OBSERVED->value && $row->deepCard !== null
          ? $row->deepCard : $row->quickCard)];
      // A record of a summon reads that summon's own information rather than a copy of it.
      $summon = $row->summonId === null ? null : $this->findSummon($row->summonId);
      if ($summon !== null) { array_push($sections, ...SummonInfoSheet::createSections($summon)); }
      // What battles have revealed about this kind of foe: its HP, MP, stats and best element.
      array_push($sections, ...EnemyInsightSheet::createSections($knowledge, $row, $this->findEnemies($row)));
      // Retain the owner's first earned report, including amended/withdrawn status.
      foreach ($knowledge->catalog->reportsFor($row->id) as $report) {
        $state = $knowledge->progress->reportState($row->id, $report->id);
        if ($state === null) { continue; }
        $sections[] = [...self::createProse($report->title),
          ...self::createField(ucfirst(strval($state['status'] ?? 'active')), $report->summary),
          ...self::createProse($report->details ?? '')];
        break;
      }
    }
    if ($row instanceof TutorialDefinition) {
      $sections[] = [MenuDetailEntry::createProse($row->title)];
      if ($this->viewingDetail) {
        foreach ($row->steps as $step) {
          array_push($sections[0], ...self::createProse($step->say ?? ''));
        }
      }
    }
    $tabs = $this->getTabs();
    $labels = array_map(fn(RecordsMenuTab $tab): string => match ($tab) {
      RecordsMenuTab::ACHIEVEMENTS => 'Achievements (' . count($manager?->unlocked ?? []) . '/' . count($manager?->achievements ?? []) . ')',
      RecordsMenuTab::FIELD_INDEX => 'Field Index (' . count($knowledge->discoveredSubjects()) . ')',
      RecordsMenuTab::GUIDE => 'Guide',
    }, $tabs);
    return new JournalMenuContent('Records', $labels, (int)array_search($this->tab, $tabs, true),
      $this->tab === RecordsMenuTab::GUIDE ? '' : 'Points: ' . ($manager?->earnedPoints() ?? 0), $rows, $this->activeIndex,
      match ($this->tab) {
        RecordsMenuTab::ACHIEVEMENTS => 'No achievements authored.',
        RecordsMenuTab::FIELD_INDEX => 'No field records discovered.',
        RecordsMenuTab::GUIDE => 'No guides discovered.',
      },
      $row === null ? '' : $this->tab->value . ':' . $row->id, new MenuDetailSheet($sections), '', $this->viewingDetail, false,
      confirmLabel: $row instanceof TutorialDefinition ? $this->getGuideAction($row) : 'View details');
  }

  public function getPresentationPage(MenuDetailViewport $area, MenuDetailStyle $style = new MenuDetailStyle()): TextPage
  {
    $content = $this->getPresentationContent();
    $reading = clone $this->reading;
    if ($content->entryKey !== $this->readingKey) { $reading->reset(); }
    return $content->details->createPage($reading, $area, $style);
  }

  protected function composeMenuCanvas(MenuPresentationCatalog $theme, float $time): ?PresentationCanvas
  {
    if ($this->demo !== null) {
      $runtime = $this->getGameScene()->getGame()->getRendererRuntime();
      $stage = $this->demo->getCanvas(PresentationCanvas::DEFAULT_WIDTH, PresentationCanvas::DEFAULT_HEIGHT,
        Accessibility::prefersReducedMotion(), $runtime?->supports(RendererSessionConfig::CANVAS_IMAGE_FLIP) ?? false);
      if ($stage !== null) {
        $view = new MenuCanvas($theme, $stage->width, $stage->height, $time, background: false);
        $hint = [ActionHints::resolve('cancel', 'Back')];
        $padding = $theme->metrics->panelPadding;
        $height = $theme->metrics->cellHeight;
        $view->hints('guide-demo-back', $hint, new CanvasRectangle($padding, $stage->height - $height - $padding,
          $stage->width - 2 * $padding, $height), required: true);
        return PresentationCanvas::composeOverlay($stage, $view->finish(), 'guide:');
      }
    }
    $content = $this->getPresentationContent();
    $page = $this->getPresentationPage(JournalMenuPresentation::pageSize($content, $theme), $theme->createDetailStyle());
    $canvas = JournalMenuPresentation::compose($content, $theme, $page, $time);
    $this->displayedPage = $page;
    return $canvas;
  }

  private function syncReading(JournalMenuContent $content): void
  {
    if ($content->entryKey !== $this->readingKey) { $this->reading->reset(); }
    $this->readingKey = $content->entryKey;
    $this->reading->setText($content->details->text);
  }

  protected function initializeUI(): void
  {
    $width = min(self::MENU_WIDTH, max(20, get_screen_width()));
    $left = max(0, intdiv(get_screen_width() - $width, 2));
    $height = self::SUMMARY_PANEL_HEIGHT + self::LIST_PANEL_HEIGHT + self::INFO_PANEL_HEIGHT;
    $top = max(0, intdiv(get_screen_height() - $height, 2));
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
    $metadata = !$this->viewingDetail && $content->rows !== [] && $content->confirmLabel !== null
      ? [$content->confirmLabel] : [];
    $page = $content->details->createPage($this->reading, new MenuDetailViewport($width, $this->infoPanel->getContentHeight() - count($metadata)),
      MenuDetailStyle::createTerminal());
    $this->displayedPage = $page;
    $this->infoPanel->setHelp($this->viewingDetail ? 'Details - ' . $page->range() : '');
    $lines = $content->details->layout($width, MenuDetailStyle::createTerminal())->getTerminalLines($page, $width);
    $this->infoPanel->setContent(array_pad([...$metadata, ...$lines], $this->infoPanel->getContentHeight(), ''));
    $this->summaryPanel->render();
    $this->listPanel->render();
    $this->infoPanel->render();
  }

  /** What players see a record's type called: the project's label for it. */
  private function getRecordTypeName(KnowledgeSubject $subject): string
  {
    return $this->getGameScene()->knowledge->catalog->getRecordType($subject->recordType)?->name ?? $subject->recordType;
  }

  /** @return list<Enemy> The project's enemies this subject is the record of, as authored. */
  private function findEnemies(KnowledgeSubject $subject): array
  {
    $store = ConfigStore::has(EnemyStore::class) ? ConfigStore::get(EnemyStore::class) : null;
    if (! $store instanceof EnemyStore) { return []; }
    $catalog = $this->getGameScene()->knowledge->catalog;

    return array_values(array_filter($store->all(),
      static fn(Enemy $enemy): bool => $catalog->subjectForEnemy($enemy)?->id === $subject->id));
  }

  private function findSummon(string $id): ?SummonCutsceneDefinition
  {
    $this->summons ??= array_column((new SummonCutsceneLibrary())->load(), null, 'id');
    return $this->summons[$id] ?? null;
  }

  /** @return list<MenuDetailEntry> Authored prose may be absent. */
  private static function createProse(string $text): array
  {
    return trim($text) === '' ? [] : [MenuDetailEntry::createProse($text)];
  }

  /** @return list<MenuDetailEntry> An authored value may be absent; its field is then omitted. */
  private static function createField(string $label, string $value): array
  {
    return trim($value) === '' ? [] : [MenuDetailEntry::createField($label, $value)];
  }

  /** @return non-empty-list<RecordsMenuTab> */
  private function getTabs(): array
  {
    return $this->getGameScene()->tutorialDirector === null
      ? [RecordsMenuTab::ACHIEVEMENTS, RecordsMenuTab::FIELD_INDEX]
      : RecordsMenuTab::cases();
  }

  private function getGuideAction(TutorialDefinition $guide): ?string
  {
    return match ($guide->replay) {
      TutorialReplayMode::PRACTICE => $this->getGameScene()->tutorialDirector?->canStartPractice($guide) ? 'Practice' : null,
      TutorialReplayMode::DEMO => $this->getGameScene()->getGame()->getRendererRuntime() === null ? 'Read' : 'Watch',
      TutorialReplayMode::NONE => null,
    };
  }

  private function activateGuide(TutorialDefinition $guide): void
  {
    $scene = $this->getGameScene();
    if ($guide->replay === TutorialReplayMode::PRACTICE && $scene->tutorialDirector?->startPractice($guide->id)) {
      $this->setState($scene->mainMenuState);
      return;
    }
    if ($guide->replay !== TutorialReplayMode::DEMO || $guide->demo === null) {
      return;
    }

    $runtime = $scene->getGame()->getRendererRuntime();
    if ($runtime !== null) {
      try {
        $root = $runtime->getAssetRoot();
        $timeline = (new EffectTimelineLibrary($root))->loadStage($guide->demo);
        $seconds = (new EffectPlaybackTiming($timeline))->durationSeconds;
        $this->demo = new CinematicStageSession($timeline, $root, $seconds);
        $this->demoElapsed = 0.0;
        return;
      } catch (InvalidArgumentException|RuntimeException $error) {
        Debug::warn('Guide demo ' . $guide->id . ': ' . $error->getMessage());
      }
    }

    $this->viewingDetail = true;
    $this->reading->reset();
    $this->refreshUI();
  }

  private function releaseDemo(): void
  {
    $this->demo?->release();
    $this->demo = null;
  }

  public function exit(): void
  {
    $this->releaseDemo();
  }

  public function resume(): void
  {
    $this->reloadRows();
    $this->refreshUI();
  }
}
