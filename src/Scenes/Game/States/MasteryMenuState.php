<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Menu\MagicMenu\Windows\MagicListPanel;
use Ichiloto\Engine\Core\Menu\MagicMenu\Windows\MagicTabPanel;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Skills\Skill;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Localization\Vocabulary;
use Ichiloto\Engine\Progression\Mastery\MasteryCatalog;
use Ichiloto\Engine\Progression\Mastery\MasteryLayout;
use Ichiloto\Engine\Progression\Mastery\MasteryMap;
use Ichiloto\Engine\Progression\Mastery\MasteryNodeState;
use Ichiloto\Engine\Progression\Mastery\MasteryNodeStatus;
use Ichiloto\Engine\Progression\Mastery\MasteryProgression;
use Ichiloto\Engine\Progression\Mastery\MasteryRefusal;
use Ichiloto\Engine\Progression\Mastery\MasteryVocabulary;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Accessibility;
use Ichiloto\Engine\UI\Presentation\MasteryMenuContent;
use Ichiloto\Engine\UI\Presentation\MasteryMenuNode;
use Ichiloto\Engine\UI\Presentation\MasteryMenuPresentation;
use Ichiloto\Engine\UI\Presentation\MasteryPointsCount;
use Ichiloto\Engine\UI\Presentation\MenuCanvasState;
use Ichiloto\Engine\UI\Presentation\MenuDetailEntry;
use Ichiloto\Engine\UI\Presentation\MenuDetailSheet;
use Ichiloto\Engine\UI\Presentation\MenuDetailStyle;
use Ichiloto\Engine\UI\Presentation\MenuDetailViewport;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\MenuRowColumn;
use Ichiloto\Engine\UI\Presentation\MenuTableText;
use Ichiloto\Engine\UI\Text\TextViewport;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Debug;

/**
 * One character's Mastery Map: their own skills in the project's regions,
 * what each costs in Mastery points (SP), and the points they hold. The
 * player picks any open skill, confirms its price, and it is learned at
 * once. The Terminal shows the map as a list per region; a graphical
 * renderer draws it as a map. Both read one projection and one focus, so a
 * choice means the same thing in either.
 *
 * Every word the player reads follows docs/ui-language-and-presentation.md.
 * The points label ("SP") and its spelled-out name ("Skill Points") are the
 * project's vocabulary (MasteryVocabulary).
 *
 * @package Ichiloto\Engine\Scenes\Game\States
 */
class MasteryMenuState extends GameSceneState implements CanvasProviderInterface
{
    use MenuCanvasState;

    protected const int MENU_WIDTH = 110;
    protected const int MENU_HEIGHT = 35;
    protected const int SUMMARY_HEIGHT = 6;
    protected const int TAB_HEIGHT = 3;
    protected const int CONTENT_HEIGHT = 22;
    protected const int DETAIL_WIDTH = 46;
    protected const int INFO_HEIGHT = 4;

    /** @var Character|null The character whose map is shown. */
    public ?Character $character = null;
    /** @var int The focused region. */
    protected int $regionIndex = 0;
    /** @var array<int, int> The focused node in each region. */
    protected array $nodeIndexes = [];
    protected ?string $statusMessage = null;
    /** @var MasteryPointsCount|null The last spend, counting down on graphical renderers. */
    protected ?MasteryPointsCount $pointsCount = null;
    protected ?Window $summaryPanel = null;
    protected ?MagicTabPanel $tabPanel = null;
    protected ?Window $detailPanel = null;
    protected ?MagicListPanel $listPanel = null;
    protected ?Window $infoPanel = null;
    private array $infoSelection = [];

    /** Whether the running project gives any character a Mastery Map. */
    public static function projectHasMaps(): bool
    {
        return MasteryCatalog::getProjectCatalog()->getMaps() !== [];
    }

    protected function composeMenuCanvas(MenuPresentationCatalog $theme, float $time): ?PresentationCanvas
    {
        $content = $this->getPresentationContent();

        return $content === null ? null : MasteryMenuPresentation::compose($content, $theme, $time);
    }

    public function getPresentationContent(): ?MasteryMenuContent
    {
        $actor = $this->character;
        if ($actor === null) {
            return null;
        }
        $statuses = $this->describeNodes();
        $regionIds = $this->getRegionIds();
        $focused = $this->getFocusedStatus();
        $map = $this->getMap();
        $places = $map === null ? [] : MasteryLayout::place($map, $regionIds);
        $nodes = array_map(fn(MasteryNodeStatus $status): MasteryMenuNode => new MasteryMenuNode(
            $status->node->id, $this->getNodeLabel($status), max(0, (int)array_search($status->node->region, $regionIds, true)),
            $status->state, $this->describeCardState($status), $status->node->requires,
            isset($places[$status->node->id]) ? ['x' => $places[$status->node->id]['x'], 'y' => $places[$status->node->id]['y']] : null,
            $status->affordable), $statuses);

        return new MasteryMenuContent($actor, $this->getTitle(), $this->getRegionLabels(), $this->regionIndex, array_values($nodes),
            $focused?->node->id, $this->getSummary(), $focused === null ? null : $this->getDetailSheet($focused),
            $this->getEmptyText(), $this->getDescription(), $this->statusMessage, $this->menuInfoText,
            MasteryVocabulary::getPointsLabel(), $actor->mastery->points, $this->pointsCount);
    }

    public function enter(): void
    {
        $this->resetMenuPresentation();
        Console::clear();
        $this->getGameScene()->locationHUDWindow->deactivate();
        $this->character ??= $this->getGameScene()->party->leader;
        $this->statusMessage = null;
        $this->pointsCount = null;
        $this->initializeUI();
        $this->normalizeFocus();
        $this->refreshUI();
    }

    public function execute(?SceneStateContext $context = null): void
    {
        $this->syncCounterTick();
        $this->syncInfoSelection();
        if ($this->handleMenuInfoInput($this->getDescription(), $this->statusMessage,
            max(1, ($this->infoPanel?->getContentWidth() ?? self::MENU_WIDTH - 2) - 2))) {
            $this->infoPanel?->setContent($this->buildInfoLines());
            $this->infoPanel?->render();
            return;
        }
        if ($this->isNextCharacterRequested()) {
            $this->selectCharacterByOffset(1);
            return;
        }
        if ($this->isPreviousCharacterRequested()) {
            $this->selectCharacterByOffset(-1);
            return;
        }
        $horizontal = Input::getAxis(AxisName::HORIZONTAL);
        $vertical = Input::getAxis(AxisName::VERTICAL);
        if ($horizontal !== 0.0 && count($this->getRegionIds()) > 0) {
            play_sound(SystemSound::CURSOR);
            $this->regionIndex = wrap($this->regionIndex + ($horizontal > 0 ? 1 : -1), 0, count($this->getRegionIds()) - 1);
            $this->statusMessage = null;
            $this->normalizeFocus();
            $this->refreshUI();
            return;
        }
        if ($vertical !== 0.0 && ($count = count($this->getRegionStatuses())) > 0) {
            play_sound(SystemSound::CURSOR);
            $this->nodeIndexes[$this->regionIndex] = wrap(($this->nodeIndexes[$this->regionIndex] ?? 0) + ($vertical > 0 ? 1 : -1), 0, $count - 1);
            $this->statusMessage = null;
            $this->refreshUI();
            return;
        }
        if (Input::isButtonDown('cancel') || Input::isButtonDown('back')) {
            play_sound(SystemSound::CANCEL);
            $this->statusMessage = null;
            $this->setState($this->getGameScene()->mainMenuState);
            return;
        }
        if (Input::isButtonDown('confirm')) {
            $this->learnFocusedNode();
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
        // A closed menu shows no count, so it holds no tick.
        AudioManager::getCurrentInstance()?->counterSound->releaseTick($this);
    }

    /**
     * Holds the shared counter tick on each frame a drawn SP total is counting down,
     * and releases it once the count ends or nothing draws it (the Terminal and reduced
     * motion show the new total at once). See docs/audio.md.
     */
    protected function syncCounterTick(): void
    {
        $counter = AudioManager::getCurrentInstance()?->counterSound;
        if ($this->isMenuCanvasShown && $this->pointsCount?->isCountingAt($this->getPresentationTime()) === true) {
            $counter?->holdTick($this);
        } else {
            $counter?->releaseTick($this);
        }
    }

    /**
     * Asks the player to confirm spending the focused skill's price, then
     * learns it at once; says why not when it cannot be learned.
     */
    protected function learnFocusedNode(): void
    {
        $focused = $this->getFocusedStatus();
        $progression = $this->getProgression();
        $character = $this->character;
        if ($focused === null || $progression === null || ! $character instanceof Character) {
            play_sound(SystemSound::BUZZER);
            return;
        }
        if (! $focused->learnable) {
            play_sound(SystemSound::BUZZER);
            $this->statusMessage = $this->describeRefusal($character, $focused);
            $this->refreshUI();
            return;
        }
        try {
            if (! confirm(sprintf('Learn %s for %d %s?', $focused->node->skill, $focused->node->cost, MasteryVocabulary::getPointsLabel()),
                $this->getTitle(), confirmLabel: 'Yes', cancelLabel: 'No')) {
                return;
            }
            $before = $character->mastery->points;
            $advance = $progression->learn($character, $focused->node->id);
            play_sound(SystemSound::CONFIRM);
            // The total counts down where motion is shown; the Terminal and reduced motion show the new total at once.
            $this->pointsCount = Accessibility::prefersReducedMotion() ? null
                : new MasteryPointsCount($before, $character->mastery->points, $this->getPresentationTime());
            $this->syncCounterTick();
            $this->statusMessage = sprintf('%s learned %s!', $character->name,
                implode(', ', array_map(static fn(Skill $skill): string => $skill->name, $advance->learned)));
        } catch (MasteryRefusal $refusal) {
            // The menu refuses what cannot be learned itself, so this is the map changing underneath it.
            play_sound(SystemSound::BUZZER);
            Debug::warn(sprintf('Mastery: %s', $refusal->getMessage()));
        } finally {
            // The confirming key must not reach the menu again once the dialog closes.
            InputManager::resetState();
            if (! $this->getGameScene()->getGame()->hasStopped()) {
                $this->refreshUI();
            }
        }
    }

    /** Why the focused skill cannot be learned, in one plain sentence; null when it is a secret. */
    protected function describeRefusal(Character $character, MasteryNodeStatus $status): ?string
    {
        return match (true) {
            $status->secret => null,
            $status->state === MasteryNodeState::LEARNED => sprintf('%s has already learned %s.', $character->name, $status->node->skill),
            $status->state === MasteryNodeState::LOCKED => implode(' ', array_map(static fn(string $condition): string => "{$condition}.",
                $this->describeLock($status))),
            default => sprintf('Not enough %s.', MasteryVocabulary::getPointsLabel()),
        };
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
        $this->statusMessage = null;
        $this->pointsCount = null;
        $this->syncCounterTick();
        $this->nodeIndexes = [];
        $this->normalizeFocus();
        $this->refreshUI();
    }

    protected function initializeUI(): void
    {
        $left = max(0, intdiv(get_screen_width() - self::MENU_WIDTH, 2));
        $top = max(0, intdiv(get_screen_height() - self::MENU_HEIGHT, 2));
        $border = new DefaultBorderPack();
        $this->summaryPanel = new Window($this->getTitle(), '', new Vector2($left, $top), self::MENU_WIDTH, self::SUMMARY_HEIGHT, $border);
        $this->tabPanel = new MagicTabPanel('', '', new Vector2($left, $top + self::SUMMARY_HEIGHT), self::MENU_WIDTH, self::TAB_HEIGHT, $border);
        $contentTop = $top + self::SUMMARY_HEIGHT + self::TAB_HEIGHT;
        $this->detailPanel = new Window('Details', '', new Vector2($left, $contentTop), self::DETAIL_WIDTH, self::CONTENT_HEIGHT, $border);
        $this->listPanel = new MagicListPanel('Skills', '', new Vector2($left + self::DETAIL_WIDTH, $contentTop),
            self::MENU_WIDTH - self::DETAIL_WIDTH, self::CONTENT_HEIGHT, $border);
        $this->infoPanel = new Window('Info', '', new Vector2($left, $contentTop + self::CONTENT_HEIGHT), self::MENU_WIDTH, self::INFO_HEIGHT, $border);
    }

    protected function refreshUI(): void
    {
        $this->syncInfoSelection();
        $this->summaryPanel?->setContent($this->buildSummaryLines());
        $this->summaryPanel?->render();
        $this->tabPanel?->setTabs($this->getRegionLabels(), $this->regionIndex);
        $this->detailPanel?->setContent($this->buildDetailLines());
        $this->detailPanel?->render();
        $this->listPanel?->setEntries($this->buildListEntries(), $this->nodeIndexes[$this->regionIndex] ?? 0);
        $this->infoPanel?->setContent($this->buildInfoLines());
        $this->infoPanel?->render();
    }

    /** @return list<string> */
    protected function buildSummaryLines(): array
    {
        if (! $this->character instanceof Character) {
            return array_fill(0, self::SUMMARY_HEIGHT - 2, '');
        }
        // Name, level and the SP total sit together, as one corner block; the Terminal shows the total at once.
        $lines = [sprintf(' %s   %s %d   %s %d', $this->character->name, Vocabulary::getTerm('stats.level_short'), $this->character->level,
            MasteryVocabulary::getPointsLabel(), $this->character->mastery->points)];
        foreach ($this->getSummary() as $label => $value) {
            $lines[] = sprintf(' %s: %s', $label, $value);
        }

        return array_pad(array_slice($lines, 0, self::SUMMARY_HEIGHT - 2), self::SUMMARY_HEIGHT - 2, '');
    }

    /** @return list<string> Each skill on one line, its status or price right-aligned in one shared column. */
    protected function buildListEntries(): array
    {
        $width = self::MENU_WIDTH - self::DETAIL_WIDTH - 6;
        $statuses = $this->getRegionStatuses();
        $values = array_map($this->describeState(...), $statuses);
        $columns = [MenuRowColumn::fit($values)];
        $labelCells = max(1, $width - MenuTableText::getColumnCells($columns));

        return array_map(fn(MasteryNodeStatus $status, string $value): string =>
            MenuTableText::formatRow($this->getNodeLabel($status), [$value], $columns, $labelCells), $statuses, $values);
    }

    /** @return list<string> The focused skill's detail sheet, in the shared Terminal columns. */
    protected function buildDetailLines(): array
    {
        $availableLines = self::CONTENT_HEIGHT - 2;
        $focused = $this->getFocusedStatus();
        if ($focused === null) {
            return [' ' . $this->getEmptyText()];
        }
        $sheet = $this->getDetailSheet($focused);
        $width = self::DETAIL_WIDTH - 4;
        $style = MenuDetailStyle::createTerminal();
        $page = $sheet->createPage(new TextViewport(), new MenuDetailViewport($width, $availableLines), $style);

        return array_pad($sheet->layout($width, $style)->getTerminalLines($page, $width), $availableLines, '');
    }

    /** @return list<string> */
    protected function buildInfoLines(): array
    {
        $page = $this->menuInfoText->getPage($this->getDescription(), $this->statusMessage,
            max(1, ($this->infoPanel?->getContentWidth() ?? self::MENU_WIDTH - 2) - 2));
        $this->infoPanel?->setHelp($page->total > 2 ? $page->range() : 'enter:Learn  c:Back  tab:Next');

        return array_pad($page->lines, 2, '');
    }

    /** @return array<string, string> How many of the character's skills are learned. */
    protected function getSummary(): array
    {
        $statuses = $this->describeNodes();
        if ($statuses === []) {
            return [];
        }
        $learned = count(array_filter($statuses, static fn(MasteryNodeStatus $status): bool => $status->state === MasteryNodeState::LEARNED));

        return ['Learned' => sprintf('%d/%d', $learned, count($statuses))];
    }

    /**
     * The focused skill as every renderer shows it: its name and status,
     * its SP price and what using it takes, then its description. A secret
     * shows nothing but "???".
     */
    protected function getDetailSheet(MasteryNodeStatus $status): MenuDetailSheet
    {
        if ($status->secret) {
            return new MenuDetailSheet([[MenuDetailEntry::createProse('???', 'accent'), MenuDetailEntry::createProse('???', 'muted')]]);
        }
        $heading = [MenuDetailEntry::createProse($status->node->skill, 'accent')];
        foreach ($this->describeDetailStatus($status) as $line) {
            $heading[] = MenuDetailEntry::createProse($line, $status->state === MasteryNodeState::LEARNED ? 'increase' : 'muted');
        }
        $fields = [];
        if ($status->state !== MasteryNodeState::LEARNED) {
            $fields[] = MenuDetailEntry::createNumber(MasteryVocabulary::getPointsLabel(), (string)$status->node->cost);
        }
        $skill = $this->findSkill($status);
        if ($skill !== null) {
            $fields[] = MenuDetailEntry::createNumber(Vocabulary::getLabel('stats.mp', 'MP') . ' Cost', (string)$skill->cost);
            $fields[] = MenuDetailEntry::createField('Target', $skill->scope->side->value . ' / ' . $skill->scope->number->value);
        }
        $description = trim($skill?->description ?? '');

        return new MenuDetailSheet([[...$heading, ...$fields], $description === '' ? [] : [MenuDetailEntry::createProse($description)]]);
    }

    /** @return list<string> The focused skill's status under its name: learned, too dear, or what unlocks it. */
    protected function describeDetailStatus(MasteryNodeStatus $status): array
    {
        return match (true) {
            $status->state === MasteryNodeState::LEARNED => [$this->getLearnedText()],
            $status->state === MasteryNodeState::LOCKED => $this->describeLock($status),
            ! $status->affordable => [sprintf('Not enough %s', MasteryVocabulary::getPointsLabel())],
            default => [],
        };
    }

    /** The node's status in words, as the Terminal list shows it: learned, its price, or what unlocks it. */
    protected function describeState(MasteryNodeStatus $status): string
    {
        return match (true) {
            $status->secret => '???',
            $status->state === MasteryNodeState::LEARNED => $this->getLearnedText(),
            $status->state === MasteryNodeState::OPEN => (string)$status->node->cost,
            $status->opensAtLevel !== null => sprintf('Unlocks at %s %d', Vocabulary::getTerm('stats.level_short'), $status->opensAtLevel),
            default => 'Locked',
        };
    }

    /** The node's status as a map card has room for it. */
    protected function describeCardState(MasteryNodeStatus $status): string
    {
        return $status->state === MasteryNodeState::LOCKED && ! $status->secret && $status->opensAtLevel !== null
            ? sprintf('%s %d', Vocabulary::getTerm('stats.level_short'), $status->opensAtLevel)
            : $this->describeState($status);
    }

    /** "Learned" with its mark. */
    protected function getLearnedText(): string
    {
        return "\u{2713} Learned";
    }

    /** The node's name as menus show it: its skill, or "???" while it is a secret. */
    protected function getNodeLabel(MasteryNodeStatus $status): string
    {
        return $status->secret ? '???' : $status->node->skill;
    }

    /** @return list<string> What still unlocks a locked node, one condition each ("Unlocks at Lv 12"). */
    protected function describeLock(MasteryNodeStatus $status): array
    {
        if ($status->secret) {
            return ['???'];
        }
        $conditions = [];
        if ($status->opensAtLevel !== null) {
            $conditions[] = sprintf('Unlocks at %s %d', Vocabulary::getTerm('stats.level_short'), $status->opensAtLevel);
        }
        if ($status->missing !== []) {
            $map = $this->getMap();
            $conditions[] = sprintf('Unlocked after learning %s', implode(', ',
                array_map(static fn(string $required): string => $map?->findNode($required)?->skill ?? $required, $status->missing)));
        }

        return $conditions;
    }

    /** The help line: what to do here, spelling out the points label once. */
    protected function getDescription(): string
    {
        $points = sprintf('Battles and quests earn %s (%s).', MasteryVocabulary::getPointsName(), MasteryVocabulary::getPointsLabel());

        return $this->getFocusedStatus() === null ? $points : 'Choose a skill to learn. ' . $points;
    }

    protected function getEmptyText(): string
    {
        return 'No skills to learn.';
    }

    protected function getTitle(): string
    {
        return Vocabulary::getTerm('menu.mastery', 'Mastery');
    }

    /** @return list<MasteryNodeStatus> */
    protected function describeNodes(): array
    {
        return $this->character === null ? [] : ($this->getProgression()?->describe($this->character) ?? []);
    }

    /** @return list<MasteryNodeStatus> The focused region's nodes. */
    protected function getRegionStatuses(): array
    {
        $region = $this->getRegionIds()[$this->regionIndex] ?? null;

        return array_values(array_filter($this->describeNodes(), static fn(MasteryNodeStatus $status): bool => $status->node->region === $region));
    }

    protected function getFocusedStatus(): ?MasteryNodeStatus
    {
        return $this->getRegionStatuses()[$this->nodeIndexes[$this->regionIndex] ?? 0] ?? null;
    }

    /** Opens on the region of the first skill the character can learn now, so the player sees what is ready. */
    protected function normalizeFocus(): void
    {
        $regions = $this->getRegionIds();
        if ($this->nodeIndexes === [] && ($ready = array_find($this->describeNodes(),
            static fn(MasteryNodeStatus $status): bool => $status->learnable)) !== null) {
            $this->regionIndex = max(0, (int)array_search($ready->node->region, $regions, true));
            $this->nodeIndexes[$this->regionIndex] = max(0, (int)array_search($ready->node->id,
                array_map(static fn(MasteryNodeStatus $status): string => $status->node->id, $this->getRegionStatuses()), true));
        }
        $this->regionIndex = $regions === [] ? 0 : clamp($this->regionIndex, 0, count($regions) - 1);
        $count = count($this->getRegionStatuses());
        $this->nodeIndexes[$this->regionIndex] = $count === 0 ? 0 : clamp($this->nodeIndexes[$this->regionIndex] ?? 0, 0, $count - 1);
    }

    /** @return list<string> */
    protected function getRegionIds(): array
    {
        return array_map(static fn($region): string => $region->id, MasteryCatalog::getProjectCatalog()->regions);
    }

    /**
     * Each region as its tab and map heading show it: its name with the
     * character's Learned/Total count there ("Hearts 3/7"), or its name
     * alone where the character has no skills.
     *
     * @return list<string>
     */
    protected function getRegionLabels(): array
    {
        $statuses = $this->describeNodes();

        return array_map(static function ($region) use ($statuses): string {
            $here = array_filter($statuses, static fn(MasteryNodeStatus $status): bool => $status->node->region === $region->id);
            $learned = array_filter($here, static fn(MasteryNodeStatus $status): bool => $status->state === MasteryNodeState::LEARNED);

            return $here === [] ? $region->name : sprintf('%s %d/%d', $region->name, count($learned), count($here));
        }, MasteryCatalog::getProjectCatalog()->regions);
    }

    protected function getProgression(): ?MasteryProgression
    {
        return $this->character === null ? null : MasteryProgression::forCharacter($this->character);
    }

    protected function getMap(): ?MasteryMap
    {
        return $this->character === null ? null : MasteryCatalog::getProjectCatalog()->findMap($this->character->actorId);
    }

    protected function findSkill(?MasteryNodeStatus $status): ?Skill
    {
        return $status === null ? null : SkillCatalog::getProjectCatalog()->findSkill($status->node->skill);
    }

    private function syncInfoSelection(): void
    {
        $selection = [$this->character, $this->regionIndex, $this->nodeIndexes[$this->regionIndex] ?? 0];
        if ($selection !== $this->infoSelection) { $this->menuInfoText->reset(); }
        $this->infoSelection = $selection;
    }
}
