<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Menu\MagicMenu\Windows\MagicListPanel;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\IO\ActionHint;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Localization\MessageCatalog;
use Ichiloto\Engine\Localization\TargetWords;
use Ichiloto\Engine\Localization\Vocabulary;
use Ichiloto\Engine\Progression\Mastery\MasteryBoard;
use Ichiloto\Engine\Progression\Mastery\MasteryCatalog;
use Ichiloto\Engine\Progression\Mastery\MasteryFamily;
use Ichiloto\Engine\Progression\Mastery\MasteryMap;
use Ichiloto\Engine\Progression\Mastery\MasteryNodeKind;
use Ichiloto\Engine\Progression\Mastery\MasteryNodeState;
use Ichiloto\Engine\Progression\Mastery\MasteryNodeStatus;
use Ichiloto\Engine\Progression\Mastery\MasteryPhase;
use Ichiloto\Engine\Progression\Mastery\MasteryProgression;
use Ichiloto\Engine\Progression\Mastery\MasteryRefusal;
use Ichiloto\Engine\Progression\Mastery\MasteryVocabulary;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Accessibility;
use Ichiloto\Engine\UI\Presentation\MasteryCamera;
use Ichiloto\Engine\UI\Presentation\MasteryLearnHold;
use Ichiloto\Engine\UI\Presentation\MasteryMenuContent;
use Ichiloto\Engine\UI\Presentation\MasteryMenuNode;
use Ichiloto\Engine\UI\Presentation\MasteryMenuPresentation;
use Ichiloto\Engine\UI\Presentation\MasteryMenuSelection;
use Ichiloto\Engine\UI\Presentation\MasteryMenuTab;
use Ichiloto\Engine\UI\Presentation\MasteryPointsCount;
use Ichiloto\Engine\UI\Presentation\MenuCanvasState;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\MenuRowColumn;
use Ichiloto\Engine\UI\Presentation\MenuTableText;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Debug;
use RuntimeException;

/**
 * One character's Mastery board: the project's disciplines as tabs, each
 * discipline's families side by side, and each family as a tree of the
 * skills it teaches, with what each costs in Mastery points (SP) and the
 * points the character holds. Every character can learn anything on the
 * board; their role's curriculum only guides.
 *
 * Learn is held where the input reports held keys: the focused node fills
 * and the shown total drains while Learn is down, letting go, moving or
 * losing the window cancels, and the node is learned once when the hold
 * completes. Where input is event-only (the Terminal) the first press asks
 * on the footer line and a second press learns; Cancel or moving dismisses.
 * Reduced motion keeps the same words and order without fill, drain, pan
 * or flare.
 *
 * The Terminal lists one family at a time; a graphical renderer draws the
 * board. Both read one projection and one focus. Every word follows
 * docs/ui-language-and-presentation.md and the project's vocabulary.
 *
 * @package Ichiloto\Engine\Scenes\Game\States
 */
class MasteryMenuState extends GameSceneState implements CanvasProviderInterface
{
    use MenuCanvasState;

    protected const int MENU_WIDTH = 110;
    protected const int MENU_HEIGHT = 35;
    protected const int SUMMARY_HEIGHT = 5;
    protected const int TAB_HEIGHT = 4;
    protected const int LIST_HEIGHT = 18;
    protected const int DETAIL_HEIGHT = 4;
    protected const int FOOTER_HEIGHT = 4;
    /** How many family summaries an overview page holds: three rows of three. */
    public const int OVERVIEW_COLUMNS = 3;
    public const int OVERVIEW_PAGE = 9;
    /** How long the accent on a node just learned lasts, in seconds. */
    public const float FLARE_DURATION = 0.36;

    /** @var Character|null The character whose board is shown. */
    public ?Character $character = null;
    /** @var int The shown discipline. */
    protected int $regionIndex = 0;
    /** @var array<string, string> The focused node or heading in each discipline, by region id. */
    protected array $focus = [];
    /** @var array<string, string> The focused family summary in each discipline's overview, by region id. */
    protected array $overviewFocus = [];
    protected bool $overview = false;
    protected bool $curriculumShown = false;
    protected bool $infoShown = false;
    /** @var string|null The node a first Learn press asks about, on input without held keys. */
    protected ?string $pending = null;
    protected ?MasteryLearnHold $hold = null;
    /** Whether Learn must come up before it can start another hold. */
    protected bool $awaitingRelease = false;
    /** @var MasteryPointsCount|null The last spend, counting down on graphical renderers. */
    protected ?MasteryPointsCount $pointsCount = null;
    /** @var array{node: string, at: float}|null The node just learned, for its short accent. */
    protected ?array $flare = null;
    protected ?MasteryCamera $camera = null;
    private ?MasteryBoard $board = null;
    protected ?Window $summaryPanel = null;
    protected ?Window $tabPanel = null;
    protected ?MagicListPanel $listPanel = null;
    protected ?Window $detailPanel = null;
    protected ?Window $footerPanel = null;

    /** Whether the running project gives any character a Mastery Map. */
    public static function projectHasMaps(): bool
    {
        return MasteryCatalog::getProjectCatalog()->hasNodes();
    }

    protected function composeMenuCanvas(MenuPresentationCatalog $theme, float $time): ?PresentationCanvas
    {
        $runtime = $this->getGameScene()->getGame()->getRendererRuntime();
        if ($runtime !== null && ! $runtime->supports(RendererSessionConfig::CANVAS_COMPOSITING)) {
            throw new RuntimeException('The Mastery board needs canvas_compositing; terminal presentation retained.');
        }
        $content = $this->getPresentationContent();

        return $content === null ? null : MasteryMenuPresentation::compose($content, $theme, $time);
    }

    public function getPresentationContent(): ?MasteryMenuContent
    {
        $actor = $this->character;
        $board = $this->getBoard();
        $region = $this->getRegionId();
        if ($actor === null || $board === null || $region === null) {
            return null;
        }
        $statuses = $this->describeNodes();
        $families = $board->getFamilies($region);
        $focus = $this->focus[$region] ?? null;
        $familyIndex = $this->overview
            ? max(0, (int)array_search($this->overviewFocus[$region] ?? '', array_map(static fn(MasteryFamily $family): string => $family->id, $families), true))
            : max(0, (int)array_search($focus === null ? null : $board->places[$focus]['family'] ?? null,
                array_map(static fn(MasteryFamily $family): string => $family->id, $families), true));
        $curriculum = array_flip($actor->role->curriculum);
        $nodes = array_map(fn(string $id): MasteryMenuNode => $this->createMenuNode($id, $statuses, $curriculum), $board->getPlaceIds($region));

        return new MasteryMenuContent(
            $actor,
            $this->getTitle(),
            $actor->role->name,
            Vocabulary::getTerm('stats.level_short'),
            MasteryVocabulary::getPointsLabel(),
            $actor->mastery->points,
            array_map(fn($region): MasteryMenuTab => $this->createRegionTab($region->id, $region->name, $statuses), $board->tree->regions),
            $this->regionIndex,
            array_map(fn(MasteryFamily $family): MasteryMenuTab => $this->createFamilyTab($family, $statuses), $families),
            $familyIndex,
            $this->overview,
            $nodes,
            $board->widths[$region] ?? 0,
            $board->depths[$region] ?? 0,
            $this->overview ? null : $focus,
            $this->camera ?? MasteryCamera::createAt(...$this->findPlaceCentre($focus)),
            $this->describeSelection($statuses),
            $this->describeFooter($statuses),
            $this->getHints($statuses),
            $this->curriculumShown,
            $this->infoShown ? $this->describeInfo($statuses) : null,
            $this->hold,
            $this->pointsCount,
            Accessibility::prefersReducedMotion(),
            $this->getEmptyText(),
            $this->flare,
        );
    }

    public function enter(): void
    {
        $this->resetMenuPresentation();
        Console::clear();
        $this->getGameScene()->locationHUDWindow->deactivate();
        $this->character ??= $this->getGameScene()->party->leader;
        $this->board = null;
        $this->resetTransaction();
        $this->pointsCount = null;
        $this->flare = null;
        $this->overview = false;
        $this->infoShown = false;
        $this->initializeUI();
        $this->normalizeFocus();
        $this->refreshUI();
    }

    public function execute(?SceneStateContext $context = null): void
    {
        $this->syncHold();
        $this->syncCounterTick();
        if ($this->isNextCharacterRequested() || $this->isPreviousCharacterRequested()) {
            $this->selectCharacterByOffset($this->isNextCharacterRequested() ? 1 : -1);
            return;
        }
        if (Input::isButtonDown('info')) {
            play_sound(SystemSound::CURSOR);
            $this->infoShown = ! $this->infoShown;
            $this->refreshUI();
            return;
        }
        if (Input::isButtonDown('curriculum')) {
            play_sound(SystemSound::CURSOR);
            $this->curriculumShown = ! $this->curriculumShown;
            $this->refreshUI();
            return;
        }
        if (Input::isButtonDown('menu_tab_previous') || Input::isButtonDown('menu_tab_next')) {
            $this->selectRegionByOffset(Input::isButtonDown('menu_tab_next') ? 1 : -1);
            return;
        }
        if (Input::isButtonDown('menu_page_previous') || Input::isButtonDown('menu_page_next')) {
            $this->selectFamilyByOffset(Input::isButtonDown('menu_page_next') ? 1 : -1);
            return;
        }
        if (Input::isButtonDown('map_zoom')) {
            $this->toggleOverview();
            return;
        }
        $horizontal = Input::getAxis(AxisName::HORIZONTAL);
        $vertical = Input::getAxis(AxisName::VERTICAL);
        if ($horizontal !== 0.0 || $vertical !== 0.0) {
            $this->moveFocus($horizontal <=> 0.0, $vertical <=> 0.0);
            return;
        }
        if (Input::isButtonDown('cancel') || Input::isButtonDown('back')) {
            $this->cancel();
            return;
        }
        if (Input::isButtonPressed('confirm')) {
            $this->confirm();
        }
    }

    public function resume(): void
    {
        $this->refreshUI();
    }

    public function suspend(): void
    {
        $this->exit();
    }

    public function exit(): void
    {
        // A closed menu shows no count and holds nothing.
        $this->resetTransaction();
        AudioManager::getCurrentInstance()?->counterSound->releaseTick($this);
    }

    /**
     * Holds the shared counter tick on each frame a drawn SP total moves (draining while Learn is
     * held, or counting down after a spend), and releases it once it stops or nothing draws it (the
     * Terminal and reduced motion change the total at once). See docs/audio.md.
     */
    protected function syncCounterTick(): void
    {
        $counter = AudioManager::getCurrentInstance()?->counterSound;
        $time = $this->getPresentationTime();
        $moving = ! Accessibility::prefersReducedMotion()
            && ($this->hold?->isDrainingAt($time) === true || $this->pointsCount?->isCountingAt($time) === true);
        if ($this->isMenuCanvasShown && $moving) {
            $counter?->holdTick($this);
        } else {
            $counter?->releaseTick($this);
        }
    }

    /**
     * Carries Learn held down: cancelled once Learn comes up (or the window lost it), learned
     * exactly once when the hold completes. Learn must then come up before another hold.
     */
    protected function syncHold(): void
    {
        if ($this->awaitingRelease && ! Input::isButtonHeld('confirm')) {
            $this->awaitingRelease = false;
        }
        if ($this->hold === null) {
            return;
        }
        if (! Input::isButtonHeld('confirm')) {
            $this->hold = null;
            $this->refreshUI();
            return;
        }
        if ($this->hold->isCompleteAt($this->getPresentationTime())) {
            $node = $this->hold->node;
            $this->hold = null;
            $this->awaitingRelease = true;
            $this->learnNode($node, counted: false);
        }
    }

    /** Learn on the focus: opens a family from the overview, or holds, asks or learns on the board. */
    protected function confirm(): void
    {
        if ($this->overview) {
            $this->openFamily();
            return;
        }
        $status = $this->getFocusedStatus();
        if ($status === null || ! $status->learnable || $status->secret) {
            play_sound(SystemSound::BUZZER);
            $this->pending = null;
            $this->refreshUI();
            return;
        }
        if (Input::isHeldInputAvailable()) {
            if (! $this->awaitingRelease && $this->hold === null) {
                $this->hold = new MasteryLearnHold($status->node->id, $this->character->mastery->points, (int)$status->node->cost,
                    $this->getPresentationTime());
                $this->refreshUI();
            }
            return;
        }
        if ($this->pending !== $status->node->id) {
            play_sound(SystemSound::CURSOR);
            $this->pending = $status->node->id;
            $this->refreshUI();
            return;
        }
        $this->pending = null;
        $this->learnNode($status->node->id, counted: true);
    }

    /** Cancel dismisses a question, then closes Info, then leaves the menu. */
    protected function cancel(): void
    {
        play_sound(SystemSound::CANCEL);
        if ($this->pending !== null || $this->hold !== null) {
            $this->resetTransaction();
            $this->refreshUI();
            return;
        }
        if ($this->infoShown) {
            $this->infoShown = false;
            $this->refreshUI();
            return;
        }
        $this->setState($this->getGameScene()->mainMenuState);
    }

    /**
     * Learns a node on the character's own terms, spending its price once.
     *
     * @param bool $counted Whether a drawn total counts down from the old total afterwards; a held Learn has already drained it.
     */
    protected function learnNode(string $nodeId, bool $counted): void
    {
        $progression = $this->getProgression();
        $character = $this->character;
        if ($progression === null || ! $character instanceof Character) {
            return;
        }
        try {
            $before = $character->mastery->points;
            $progression->learn($character, $nodeId);
            play_sound(SystemSound::CONFIRM);
            $time = $this->getPresentationTime();
            $reduced = Accessibility::prefersReducedMotion();
            // The total counts down where motion is shown; the Terminal and reduced motion show the new total at once.
            $this->pointsCount = $counted && ! $reduced && $this->isMenuCanvasShown
                ? new MasteryPointsCount($before, $character->mastery->points, $time) : null;
            $this->flare = $reduced ? null : ['node' => $nodeId, 'at' => $time];
        } catch (MasteryRefusal $refusal) {
            // The menu refuses what cannot be learned itself, so this is the map changing underneath it.
            play_sound(SystemSound::BUZZER);
            Debug::warn(sprintf('Mastery: %s', $refusal->getMessage()));
        } finally {
            $this->syncCounterTick();
            if (! $this->getGameScene()->getGame()->hasStopped()) {
                $this->refreshUI();
            }
        }
    }

    /** Forgets a held or asked Learn without spending anything. */
    protected function resetTransaction(): void
    {
        $this->hold = null;
        $this->pending = null;
    }

    /** Seconds on the menu's presentation clock, the clock graphical renderers draw by. */
    protected function getPresentationTime(): float
    {
        return max(0, hrtime(true) / 1e9 - $this->menuPresentationStart);
    }

    protected function selectCharacterByOffset(int $offset): void
    {
        $characters = $this->getGameScene()->party->members->toArray();
        if (count($characters) < 2) {
            return;
        }
        $current = array_search($this->character, $characters, true);
        $this->character = $characters[wrap(($current === false ? 0 : $current) + $offset, 0, count($characters) - 1)];
        $this->resetTransaction();
        $this->pointsCount = null;
        $this->flare = null;
        $this->syncCounterTick();
        $this->focus = [];
        $this->overviewFocus = [];
        $this->camera = null;
        $this->normalizeFocus();
        $this->refreshUI();
    }

    protected function selectRegionByOffset(int $offset): void
    {
        $board = $this->getBoard();
        $count = count($board?->tree->regions ?? []);
        if ($count === 0) {
            return;
        }
        play_sound(SystemSound::CURSOR);
        $this->resetTransaction();
        $this->regionIndex = wrap($this->regionIndex + $offset, 0, $count - 1);
        $this->normalizeFocus();
        $this->camera = null;
        $this->refreshUI();
    }

    /** The previous or next family: its summary in the overview, its top on the board. */
    protected function selectFamilyByOffset(int $offset): void
    {
        $board = $this->getBoard();
        $region = $this->getRegionId();
        $families = $region === null ? [] : $board?->getFamilies($region) ?? [];
        if ($families === []) {
            return;
        }
        play_sound(SystemSound::CURSOR);
        $this->resetTransaction();
        $ids = array_map(static fn(MasteryFamily $family): string => $family->id, $families);
        $current = $this->overview ? ($this->overviewFocus[$region] ?? $ids[0]) : $board->places[$this->focus[$region]]['family'];
        $family = $families[wrap((int)array_search($current, $ids, true) + $offset, 0, count($families) - 1)];
        if ($this->overview) {
            $this->overviewFocus[$region] = $family->id;
        } else {
            $this->setFocus(MasteryBoard::getRootId($family));
        }
        $this->refreshUI();
    }

    /** Zooms out to the discipline's family summaries, or back in to the board at the summary in focus. */
    protected function toggleOverview(): void
    {
        $board = $this->getBoard();
        $region = $this->getRegionId();
        if ($board === null || $region === null) {
            return;
        }
        play_sound(SystemSound::CURSOR);
        $this->resetTransaction();
        if (! $this->overview) {
            $this->overviewFocus[$region] = $board->places[$this->focus[$region]]['family'];
            $this->overview = true;
            $this->refreshUI();
            return;
        }
        $this->openFamily();
    }

    /** Opens the summary in focus on the board at full size, at its top or where the board was focused in it; never learns. */
    protected function openFamily(): void
    {
        $board = $this->getBoard();
        $region = $this->getRegionId();
        $family = $board?->findFamily($this->overviewFocus[$region] ?? '');
        $this->overview = false;
        if ($family !== null && $board->places[$this->focus[$region]]['family'] !== $family->id) {
            $this->setFocus(MasteryBoard::getRootId($family));
        }
        $this->camera = null;
        play_sound(SystemSound::CONFIRM);
        $this->refreshUI();
    }

    /**
     * Moves the focus one step. The overview moves across its grid of summaries. A drawn board moves
     * along the tree: up to what a node hangs from, down to what hangs from it, across to the nearest
     * place in the next lane. The Terminal list moves up and down its rows and across families.
     */
    protected function moveFocus(int $across, int $down): void
    {
        $board = $this->getBoard();
        $region = $this->getRegionId();
        if ($board === null || $region === null) {
            return;
        }
        $this->resetTransaction();
        if ($this->overview) {
            $ids = array_map(static fn(MasteryFamily $family): string => $family->id, $board->getFamilies($region));
            $index = (int)array_search($this->overviewFocus[$region] ?? $ids[0], $ids, true);
            $next = $index + $across + $down * self::OVERVIEW_COLUMNS;
            if ($next >= 0 && $next < count($ids)) {
                play_sound(SystemSound::CURSOR);
                $this->overviewFocus[$region] = $ids[$next];
            }
            $this->refreshUI();
            return;
        }
        if (! $this->isMenuCanvasShown && $across !== 0) {
            $this->selectFamilyByOffset($across);
            return;
        }
        $target = $this->isMenuCanvasShown ? $this->findBoardNeighbour($this->focus[$region], $across, $down)
            : $this->findListNeighbour($this->focus[$region], $down);
        if ($target !== null) {
            play_sound(SystemSound::CURSOR);
            $this->setFocus($target);
        }
        $this->refreshUI();
    }

    /** The place one step from another on the drawn board, or null at its edge. */
    protected function findBoardNeighbour(string $from, int $across, int $down): ?string
    {
        $board = $this->getBoard();
        $place = $board->places[$from];
        if ($down < 0) {
            return $place['parent'];
        }
        if ($down > 0) {
            $children = $board->children[$from] ?? [];

            return array_reduce($children, static fn(?string $best, string $child): string => $best === null
                || abs($board->places[$child]['lane'] - $place['lane']) < abs($board->places[$best]['lane'] - $place['lane']) ? $child : $best);
        }
        $best = null;
        $bestScore = INF;
        foreach ($board->getPlaceIds($place['region']) as $id) {
            $candidate = $board->places[$id];
            $distance = ($candidate['lane'] - $place['lane']) * $across;
            if ($distance <= 0) {
                continue;
            }
            // The same stage first, then the nearest lane.
            $score = abs($candidate['stage'] - $place['stage']) * 1000 + $distance;
            if ($score < $bestScore) {
                $best = $id;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /** The row above or below in the Terminal's list of the focused family. */
    protected function findListNeighbour(string $from, int $down): ?string
    {
        $board = $this->getBoard();
        $ids = $board->getFamilyPlaceIds($board->findPlaceFamily($from));
        $index = (int)array_search($from, $ids, true);

        return $ids[wrap($index + $down, 0, count($ids) - 1)];
    }

    /** Focuses a place in the shown discipline; the board's view follows it. */
    protected function setFocus(string $placeId): void
    {
        $region = $this->getRegionId();
        if ($region === null) {
            return;
        }
        $this->focus[$region] = $placeId;
        [$lane, $stage] = $this->findPlaceCentre($placeId);
        $this->camera = ($this->camera ?? MasteryCamera::createAt($lane, $stage))
            ->createMove($lane, $stage, $this->getPresentationTime(), Accessibility::prefersReducedMotion() || ! $this->isMenuCanvasShown);
    }

    /** @return array{float, float} A place's lane middle and stage, or the board's corner when nothing is focused. */
    protected function findPlaceCentre(?string $placeId): array
    {
        $place = $placeId === null ? null : $this->getBoard()?->places[$placeId] ?? null;

        return $place === null ? [0.5, 0.0] : [$place['lane'], (float)$place['stage']];
    }

    protected function initializeUI(): void
    {
        $left = max(0, intdiv(get_screen_width() - self::MENU_WIDTH, 2));
        $top = max(0, intdiv(get_screen_height() - self::MENU_HEIGHT, 2));
        $border = new DefaultBorderPack();
        $y = $top;
        $this->summaryPanel = new Window('', '', new Vector2($left, $y), self::MENU_WIDTH, self::SUMMARY_HEIGHT, $border);
        $y += self::SUMMARY_HEIGHT;
        $this->tabPanel = new Window('', '', new Vector2($left, $y), self::MENU_WIDTH, self::TAB_HEIGHT, $border);
        $y += self::TAB_HEIGHT;
        $this->listPanel = new MagicListPanel('', '', new Vector2($left, $y), self::MENU_WIDTH, self::LIST_HEIGHT, $border);
        $y += self::LIST_HEIGHT;
        $this->detailPanel = new Window('', '', new Vector2($left, $y), self::MENU_WIDTH, self::DETAIL_HEIGHT, $border);
        $y += self::DETAIL_HEIGHT;
        $this->footerPanel = new Window('', '', new Vector2($left, $y), self::MENU_WIDTH, self::FOOTER_HEIGHT, $border);
    }

    /** Redraws the Terminal panels from the same projection a graphical renderer draws. */
    protected function refreshUI(): void
    {
        $content = $this->getPresentationContent();
        if ($content === null) {
            $this->listPanel?->setEntries([' ' . $this->getEmptyText()]);
            return;
        }
        $this->summaryPanel?->setContent($this->buildSummaryLines($content));
        $this->summaryPanel?->render();
        $this->tabPanel?->setContent($this->buildTabLines($content));
        $this->tabPanel?->render();
        [$rows, $active] = $this->buildListRows($content);
        $this->listPanel?->setEntries($rows, $active);
        $this->detailPanel?->setContent($this->buildDetailLines($content));
        $this->detailPanel?->render();
        $this->footerPanel?->setContent($this->buildFooterLines($content));
        $this->footerPanel?->render();
    }

    /** @return list<string> The corner block: the title, the character, and their level and points together. */
    protected function buildSummaryLines(MasteryMenuContent $content): array
    {
        return [' ' . $content->title, ' ' . $content->character->name,
            sprintf(' %s %d   %s %d', $content->levelLabel, $content->character->level, $content->pointsLabel, $content->points)];
    }

    /** @return list<string> The disciplines, the shown one in brackets, then where the list is. */
    protected function buildTabLines(MasteryMenuContent $content): array
    {
        $tabs = implode('   ', array_map(static fn(MasteryMenuTab $tab, int $index): string => $index === $content->regionIndex
            ? "[{$tab->name}]" : " {$tab->name} ", $content->regions, array_keys($content->regions)));
        $region = $content->findRegion()?->name ?? '';
        $family = $content->families[$content->familyIndex]->name ?? '';

        return [' ' . $tabs, ' ' . ($content->overview || $family === '' ? $region : "{$region} / {$family}")];
    }

    /**
     * The list rows, each a name with its status or price right-aligned in one shared column: the
     * shown discipline's family summaries in the overview, the focused family on the board.
     *
     * @return array{list<string>, int} The rows and the focused row.
     */
    protected function buildListRows(MasteryMenuContent $content): array
    {
        $width = self::MENU_WIDTH - 6;
        if ($content->overview) {
            $labels = array_map(static fn(MasteryMenuTab $tab): string => $tab->name, $content->families);
            $values = array_map(fn(MasteryMenuTab $tab): string => $this->describeCount($tab->learned, $tab->total), $content->families);
            $active = $content->familyIndex;
        } else {
            $family = $content->families[$content->familyIndex]->id ?? null;
            $nodes = array_values(array_filter($content->nodes, static fn(MasteryMenuNode $node): bool => $node->family === $family));
            $labels = array_map(static fn(MasteryMenuNode $node): string => $node->label, $nodes);
            $values = array_map(fn(MasteryMenuNode $node): string => $this->describeRowValue($node, $content), $nodes);
            $active = max(0, (int)array_search($content->focus, array_map(static fn(MasteryMenuNode $node): string => $node->id, $nodes), true));
        }
        $columns = [MenuRowColumn::fit($values, HorizontalAlignment::LEFT)];
        $labelCells = max(1, min(48, max(array_map(TerminalText::displayWidth(...), [...$labels, '']))) + 4);
        $labelCells = min($labelCells, $width - MenuTableText::getColumnCells($columns));

        return [array_map(static fn(string $label, string $value): string => ' ' . MenuTableText::formatRow($label, [$value], $columns, $labelCells),
            $labels, $values), $active];
    }

    /** @return list<string> What the focus is: its casting values or counts, or its Info sheet while that is open. */
    protected function buildDetailLines(MasteryMenuContent $content): array
    {
        if ($content->info !== null) {
            return array_map(static fn(string $line): string => ' ' . $line, array_slice($content->info, 0, self::DETAIL_HEIGHT - 2));
        }
        if ($this->pending !== null) {
            return ['', ''];
        }
        $facts = implode('   ', array_map(static fn(array $fact): string => $fact['text'], $content->footer));
        // A node's status follows its casting values; a family's or discipline's name is already on the tab line.
        $focus = $content->findFocusedNode();
        $status = $content->overview || $focus === null || $focus->isHeading() ? '' : $content->selection->subtitle ?? '';

        return [' ' . $facts, ' ' . $status];
    }

    /** @return list<string> The bindings in aligned columns, or the pending question with its price and Cancel. */
    protected function buildFooterLines(MasteryMenuContent $content): array
    {
        $width = self::MENU_WIDTH - 4;
        if ($this->pending !== null) {
            $cancel = ActionHints::resolve('cancel', Vocabulary::getTerm('mastery.cancel'));
            $cancelText = self::describeTerminalHint($cancel);

            return [' ' . ($content->footer[0]['text'] ?? ''),
                ' ' . TerminalText::padRight($content->footer[1]['text'] ?? '', $width - 2 - TerminalText::displayWidth($cancelText)) . $cancelText];
        }
        $hints = array_map(self::describeTerminalHint(...), $content->hints);
        $cell = max(array_map(TerminalText::displayWidth(...), [...$hints, ''])) + 4;
        $perRow = max(1, intdiv($width - 2, $cell));
        $lines = [];
        foreach (array_chunk($hints, $perRow) as $row) {
            $lines[] = ' ' . implode('', array_map(static fn(string $hint): string => TerminalText::padRight($hint, $cell), $row));
        }

        return array_slice($lines, 0, self::FOOTER_HEIGHT - 2);
    }

    /** A binding as the Terminal shows it: its keys, then what it does ("Q/E Suits"). */
    protected static function describeTerminalHint(ActionHint $hint): string
    {
        return str_replace(' / ', '/', $hint->describeControls()) . ' ' . $hint->label;
    }

    /**
     * A row's right-hand column in the Terminal list: a family's count on its top row, or a node's status,
     * price or what it waits for. While a first Learn press asks about a node, its price is on the footer
     * line instead, so it is said once.
     */
    protected function describeRowValue(MasteryMenuNode $node, MasteryMenuContent $content): string
    {
        if ($this->pending === $node->id) {
            return '';
        }
        if ($node->parent === null) {
            $tab = array_find($content->families, static fn(MasteryMenuTab $tab): bool => $tab->id === $node->family);

            return $tab === null ? '' : $this->describeCount($tab->learned, $tab->total);
        }
        $status = $this->findStatus($node->id);

        return match (true) {
            $status === null => '',
            $status->secret => '???',
            $status->state === MasteryNodeState::LEARNED => Vocabulary::getTerm('mastery.learned'),
            $status->state === MasteryNodeState::UNAVAILABLE => Vocabulary::getTerm('mastery.unavailable'),
            $status->state === MasteryNodeState::LOCKED => implode(' · ', $this->describeLock($status)),
            default => $node->price ?? '',
        };
    }

    /** The place under the board: the focused node's name, status, price and Learn button, or a family summary's name. */
    protected function describeSelection(array $statuses): MasteryMenuSelection
    {
        $board = $this->getBoard();
        $region = $this->getRegionId();
        $regionName = $board?->tree->regions[$this->regionIndex]->name ?? '';
        if ($this->overview) {
            $family = $board?->findFamily($this->overviewFocus[$region] ?? '');

            return new MasteryMenuSelection($family?->name ?? $regionName, $family === null ? Vocabulary::getTerm('mastery.discipline') : $regionName);
        }
        $focus = $this->focus[$region] ?? null;
        if ($focus === null || $board === null) {
            return new MasteryMenuSelection($regionName, Vocabulary::getTerm('mastery.discipline'));
        }
        if ($board->isHeading($focus)) {
            $family = $board->findPlaceFamily($focus);

            // A discipline's own trunk is named for the discipline, so its heading says what it is instead.
            return new MasteryMenuSelection($family?->name ?? '', $family?->branch === null ? Vocabulary::getTerm('mastery.discipline') : $regionName);
        }
        $status = $statuses[$focus] ?? null;
        if ($status === null || $status->secret) {
            return new MasteryMenuSelection('???', '???');
        }
        $learn = Vocabulary::getTerm('mastery.learn');
        $price = $this->describePrice($status);

        return match (true) {
            $status->state === MasteryNodeState::LEARNED => new MasteryMenuSelection($status->node->name, Vocabulary::getTerm('mastery.learned')),
            $status->state === MasteryNodeState::UNAVAILABLE => new MasteryMenuSelection($status->node->name, Vocabulary::getTerm('mastery.unavailable')),
            $status->state === MasteryNodeState::LOCKED => new MasteryMenuSelection($status->node->name, implode(' · ', $this->describeLock($status)),
                $price, $learn, false),
            ! $status->affordable => new MasteryMenuSelection($status->node->name,
                MessageCatalog::getTerm('mastery.not_enough', MasteryVocabulary::getPointsLabel()), $price, $learn, false),
            default => new MasteryMenuSelection($status->node->name,
                $status->node->kind === MasteryNodeKind::FOUNDATION ? $regionName : null, $price, $learn, true),
        };
    }

    /**
     * What the footer says about the focus: a skill's MP and, unless its badge already names it,
     * whom it reaches; a family's or discipline's counts; or the question a first Learn press asks.
     *
     * @param array<string, MasteryNodeStatus> $statuses
     * @return list<array{text: string, color: string}>
     */
    protected function describeFooter(array $statuses): array
    {
        $board = $this->getBoard();
        $region = $this->getRegionId();
        if ($board === null || $region === null) {
            return [];
        }
        if ($this->pending !== null && isset($statuses[$this->pending])) {
            $status = $statuses[$this->pending];

            return [['text' => MessageCatalog::getTerm('mastery.learn_skill', $status->node->name), 'color' => 'text'],
                ['text' => (string)$this->describePrice($status), 'color' => 'focus']];
        }
        if ($this->overview) {
            $families = $board->getFamilies($region);
            $here = array_filter($statuses, static fn(MasteryNodeStatus $status): bool => $status->node->region === $region);

            return [['text' => MessageCatalog::getTerm('mastery.family_count', count($families)), 'color' => 'muted'],
                ['text' => $this->describeCount(self::countLearned($here), count($here)), 'color' => 'text']];
        }
        $focus = $this->focus[$region] ?? null;
        $status = $focus === null ? null : $statuses[$focus] ?? null;
        if ($focus === null || $board->isHeading($focus) || $status?->node->kind === MasteryNodeKind::FOUNDATION) {
            $family = $focus === null ? null : $board->findPlaceFamily($focus);
            $here = $family === null ? [] : array_intersect_key($statuses, array_flip($family->nodes));

            return [['text' => $this->describeCount(self::countLearned($here), count($here)), 'color' => 'text']];
        }
        if ($status === null || $status->secret) {
            return [];
        }
        [$cost, $scope] = $this->findCasting($status);
        $facts = [];
        if ($cost !== null) {
            $facts[] = ['text' => Vocabulary::getLabel('stats.mp', 'MP'), 'color' => 'muted'];
            $facts[] = ['text' => (string)$cost, 'color' => 'text'];
        }
        $badge = $this->describeBadge($status);
        if ($scope !== null && ! $badge['target'] && ($words = TargetWords::describeScope($scope)) !== '') {
            $facts[] = ['text' => $words, 'color' => 'text'];
        }

        return $facts;
    }

    /** @return list<ActionHint> The footer's bindings: what the player can do here, in the approved order. */
    protected function getHints(array $statuses): array
    {
        $hints = [ActionHints::resolvePair('menu_tab_previous', 'menu_tab_next', Vocabulary::getTerm('mastery.suits')),
            ActionHints::resolvePair('menu_page_previous', 'menu_page_next', Vocabulary::getTerm('mastery.family')),
            ActionHints::resolve('curriculum', Vocabulary::getTerm('mastery.curriculum')),
            ActionHints::resolve('map_zoom', Vocabulary::getTerm($this->overview ? 'mastery.detail' : 'mastery.overview'))];
        $focus = $this->focus[$this->getRegionId() ?? ''] ?? null;
        $status = $focus === null ? null : $statuses[$focus] ?? null;
        if (! $this->overview && $status?->learnable === true && ! $status->secret) {
            $hints[] = ActionHints::resolve('confirm', Vocabulary::getTerm(Input::isHeldInputAvailable() ? 'mastery.hold_learn' : 'mastery.learn'));
        }
        $hints[] = ActionHints::resolve('info', Vocabulary::getTerm($this->infoShown ? 'mastery.close' : 'mastery.info'));

        return $hints;
    }

    /**
     * The Info sheet: the focus's description, then what it builds on.
     *
     * @param array<string, MasteryNodeStatus> $statuses
     * @return list<string>
     */
    protected function describeInfo(array $statuses): array
    {
        $board = $this->getBoard();
        $region = $this->getRegionId();
        if ($board === null || $region === null) {
            return [];
        }
        if ($this->overview) {
            $family = $board->findFamily($this->overviewFocus[$region] ?? '');

            return array_values(array_filter([trim($family?->branch?->description ?? $board->tree->regions[$this->regionIndex]->description)]));
        }
        $focus = $this->focus[$region] ?? null;
        if ($focus === null) {
            return [];
        }
        if ($board->isHeading($focus)) {
            $family = $board->findPlaceFamily($focus);

            return array_values(array_filter([trim($family?->branch?->description ?? $board->tree->regions[$this->regionIndex]->description)]));
        }
        $status = $statuses[$focus] ?? null;
        if ($status === null || $status->secret) {
            return ['???'];
        }
        $skills = SkillCatalog::getProjectCatalog();
        $skill = $status->node->skill;
        $description = $skill === null ? '' : trim($skills->findSkill($skill)?->description ?? $skills->findDraft($skill)?->getDescription() ?? '');
        $after = array_map(fn(string $id): string => $this->getMap()?->findNode($id)?->name ?? $id, $status->node->requires);

        return array_values(array_filter([$description,
            $after === [] ? '' : MessageCatalog::getTerm('mastery.after', implode(', ', $after))]));
    }

    /**
     * A node's MP cost and reach from its skill record, or from the values its draft record gives.
     *
     * @return array{?int, ?ItemScope}
     */
    protected function findCasting(MasteryNodeStatus $status): array
    {
        $name = $status->node->skill;
        if ($name === null) {
            return [null, null];
        }
        $skills = SkillCatalog::getProjectCatalog();
        $skill = $skills->findSkill($name);
        if ($skill !== null) {
            return [$skill->cost, $skill->scope];
        }
        $draft = $skills->findDraft($name);

        return [$draft?->getCost(), $draft?->getScope()];
    }

    /**
     * What a node's rim badge says: its grade (I, II, III), a special grade's phase (Demi, Full), or,
     * for any other skill, whom it reaches. A foundation and a secret show none.
     *
     * @return array{text: ?string, target: bool}
     */
    protected function describeBadge(MasteryNodeStatus $status): array
    {
        $definition = $status->node->definition;
        if ($status->secret || $definition->kind === MasteryNodeKind::FOUNDATION) {
            return ['text' => null, 'target' => false];
        }
        if ($definition->grade !== null) {
            return ['text' => self::getRomanNumeral($definition->grade), 'target' => false];
        }
        if ($definition->phase !== null) {
            return ['text' => Vocabulary::getTerm($definition->phase === MasteryPhase::DEMI ? 'mastery.demi' : 'mastery.full'), 'target' => false];
        }
        [, $scope] = $this->findCasting($status);
        $words = $scope === null ? '' : TargetWords::describeScope($scope);

        return $words === '' ? ['text' => null, 'target' => false] : ['text' => $words, 'target' => true];
    }

    /** A node's price with the points label ("45 SP"), while it can still be learned. */
    protected function describePrice(MasteryNodeStatus $status): ?string
    {
        return $status->node->cost === null || $status->state === MasteryNodeState::LEARNED || $status->state === MasteryNodeState::UNAVAILABLE
            ? null : sprintf('%d %s', $status->node->cost, MasteryVocabulary::getPointsLabel());
    }

    /** @return list<string> What still unlocks a locked node, one condition each ("Unlocks at Lv 12"). */
    protected function describeLock(MasteryNodeStatus $status): array
    {
        if ($status->secret) {
            return ['???'];
        }
        $conditions = [];
        if ($status->opensAtLevel !== null) {
            $conditions[] = MessageCatalog::getTerm('mastery.level_lock', Vocabulary::getTerm('stats.level_short'), $status->opensAtLevel);
        }
        if ($status->missing !== []) {
            $map = $this->getMap();
            $conditions[] = MessageCatalog::getTerm('mastery.requires', implode(', ',
                array_map(static fn(string $required): string => $map?->findNode($required)?->name ?? $required, $status->missing)));
        }

        return $conditions;
    }

    /** A group's count ("2 / 7 learned"). */
    protected function describeCount(int $learned, int $total): string
    {
        return MessageCatalog::getTerm('mastery.learned_count', $learned, $total);
    }

    /**
     * @param array<string, MasteryNodeStatus> $statuses
     * @param array<string, int> $curriculum
     */
    protected function createMenuNode(string $id, array $statuses, array $curriculum): MasteryMenuNode
    {
        $board = $this->getBoard();
        $place = $board->places[$id];
        $family = $board->findFamily($place['family']);
        $status = $statuses[$id] ?? null;
        if ($status === null) {
            return new MasteryMenuNode($id, $family?->name ?? $id, null, $place['lane'], $place['stage'], $place['parent'], $place['family'],
                iconRoles: $this->getFamilyIconRoles($family));
        }
        $badge = $this->describeBadge($status);

        return new MasteryMenuNode($id, $status->secret ? '???' : $status->node->name, $status->state, $place['lane'], $place['stage'],
            $place['parent'], $place['family'], $badge['text'], $badge['target'],
            ["mastery.node.{$id}", ...$this->getFamilyIconRoles($family)], $status->affordable, isset($curriculum[$id]),
            $status->secret ? null : $this->describePrice($status));
    }

    /** @param array<string, MasteryNodeStatus> $statuses */
    protected function createRegionTab(string $id, string $name, array $statuses): MasteryMenuTab
    {
        $here = array_filter($statuses, static fn(MasteryNodeStatus $status): bool => $status->node->region === $id);

        return new MasteryMenuTab($id, $name, self::countLearned($here), count($here), ["mastery.region.{$id}"]);
    }

    /** @param array<string, MasteryNodeStatus> $statuses */
    protected function createFamilyTab(MasteryFamily $family, array $statuses): MasteryMenuTab
    {
        $here = array_intersect_key($statuses, array_flip($family->nodes));

        return new MasteryMenuTab($family->id, $family->name, self::countLearned($here), count($here), $this->getFamilyIconRoles($family));
    }

    /**
     * A family's icon roles, most particular first: its own, its element's shared icon, then the
     * board's generic node. A discipline's own role pictures its tab, not its skills.
     *
     * @return list<string>
     */
    protected function getFamilyIconRoles(?MasteryFamily $family): array
    {
        if ($family === null) {
            return ['mastery.node'];
        }

        return array_values(array_filter(["mastery.family.{$family->id}",
            $family->element === null ? null : 'element.' . strtolower($family->element), 'mastery.node']));
    }

    /** @param array<string, MasteryNodeStatus> $statuses */
    protected static function countLearned(array $statuses): int
    {
        return count(array_filter($statuses, static fn(MasteryNodeStatus $status): bool => $status->state === MasteryNodeState::LEARNED));
    }

    protected static function getRomanNumeral(int $number): string
    {
        $numeral = '';
        foreach (['X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1] as $symbol => $value) {
            while ($number >= $value) {
                $numeral .= $symbol;
                $number -= $value;
            }
        }

        return $numeral;
    }

    protected function getEmptyText(): string
    {
        return 'No skills to learn.';
    }

    protected function getTitle(): string
    {
        return Vocabulary::getTerm('menu.mastery');
    }

    /**
     * Every node of the board for the character, by id: learned, open, locked, or unavailable to
     * everyone yet (its skill awaits author values, or nothing prices it). The board shows them all;
     * only open nodes whose price is held can be learned.
     *
     * @return array<string, MasteryNodeStatus>
     */
    protected function describeNodes(): array
    {
        if ($this->character === null) {
            return [];
        }
        $statuses = [];
        foreach ($this->getProgression()?->describe($this->character) ?? [] as $status) {
            $statuses[$status->node->id] = $status;
        }

        return $statuses;
    }

    protected function findStatus(string $nodeId): ?MasteryNodeStatus
    {
        return $this->describeNodes()[$nodeId] ?? null;
    }

    protected function getFocusedStatus(): ?MasteryNodeStatus
    {
        $focus = $this->focus[$this->getRegionId() ?? ''] ?? null;

        return $focus === null ? null : $this->findStatus($focus);
    }

    /**
     * Keeps a focus in every discipline shown. A character's board opens on the first node they can
     * learn now, so the player sees what is ready; otherwise on the first family's top.
     */
    protected function normalizeFocus(): void
    {
        $board = $this->getBoard();
        if ($board === null) {
            return;
        }
        $regions = $board->tree->getRegionIds();
        if ($this->focus === [] && ($ready = array_find($this->describeNodes(), static fn(MasteryNodeStatus $status): bool => $status->learnable)) !== null) {
            $this->regionIndex = max(0, (int)array_search($ready->node->region, $regions, true));
            $this->focus[$ready->node->region] = $ready->node->id;
        }
        $this->regionIndex = $regions === [] ? 0 : clamp($this->regionIndex, 0, count($regions) - 1);
        $region = $regions[$this->regionIndex] ?? null;
        if ($region === null) {
            return;
        }
        if (! isset($this->focus[$region], $board->places[$this->focus[$region]])) {
            $first = $board->getFamilies($region)[0] ?? null;
            if ($first === null) {
                unset($this->focus[$region]);
                return;
            }
            $this->focus[$region] = MasteryBoard::getRootId($first);
        }
        $this->overviewFocus[$region] ??= $board->places[$this->focus[$region]]['family'];
        $this->camera ??= MasteryCamera::createAt(...$this->findPlaceCentre($this->focus[$region]));
    }

    protected function getRegionId(): ?string
    {
        return $this->getBoard()?->tree->regions[$this->regionIndex]->id ?? null;
    }

    /** The board of the running project's tree, built once for each tree read. */
    protected function getBoard(): ?MasteryBoard
    {
        $catalog = MasteryCatalog::getProjectCatalog();
        if (! $catalog->hasNodes()) {
            return null;
        }
        if ($this->board?->tree !== $catalog->tree) {
            $this->board = new MasteryBoard($catalog->tree);
        }

        return $this->board;
    }

    protected function getProgression(): ?MasteryProgression
    {
        return $this->character === null ? null : MasteryProgression::forCharacter($this->character);
    }

    protected function getMap(): ?MasteryMap
    {
        return $this->character === null ? null : MasteryCatalog::getProjectCatalog()->findMap($this->character->actorId);
    }
}
