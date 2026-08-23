<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Menu\MagicMenu\Windows\MagicListPanel;
use Ichiloto\Engine\Core\Menu\MagicMenu\Windows\MagicTabPanel;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Magic\LearnableSpell;
use Ichiloto\Engine\Entities\Magic\SpellSortOrder;
use Ichiloto\Engine\Entities\Skills\FieldSkillExecutionResult;
use Ichiloto\Engine\Entities\Skills\FieldSkillExecutor;
use Ichiloto\Engine\Entities\Skills\FieldSkillFailureReason;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SkillTargetPolicy;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Displays the field magic-management screen for a single party member.
 *
 * @package Ichiloto\Engine\Scenes\Game\States
 */
class MagicMenuState extends GameSceneState
{
    protected const int MAGIC_MENU_WIDTH = 110;
    protected const int MAGIC_MENU_HEIGHT = 35;
    protected const int SUMMARY_PANEL_HEIGHT = 7;
    protected const int TAB_PANEL_HEIGHT = 3;
    protected const int CONTENT_PANEL_HEIGHT = 21;
    protected const int DETAIL_PANEL_WIDTH = 38;
    protected const int LIST_PANEL_WIDTH = 72;
    protected const int INFO_PANEL_HEIGHT = 4;
    /**
     * @var Character|null The character currently being viewed.
     */
    public ?Character $character = null;
    /**
     * @var string[] The tab labels for the magic screen.
     */
    protected array $tabs = ['Use', 'Learn', 'Sort'];
    /**
     * @var string[] The available sort-order labels.
     */
    protected array $sortOptions = [
        SpellSortOrder::A_TO_Z->value,
        SpellSortOrder::Z_TO_A->value,
    ];
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
     * @var Window|null The summary panel.
     */
    protected ?Window $summaryPanel = null;
    /**
     * @var MagicTabPanel|null The tab strip.
     */
    protected ?MagicTabPanel $tabPanel = null;
    /**
     * @var Window|null The left-side detail panel.
     */
    protected ?Window $detailPanel = null;
    /**
     * @var MagicListPanel|null The right-side list panel.
     */
    protected ?MagicListPanel $listPanel = null;
    /**
     * @var Window|null The bottom description and status panel.
     */
    protected ?Window $infoPanel = null;
    /**
     * @var int The active tab index.
     */
    protected int $activeTabIndex = 0;
    /**
     * @var int The active Use-tab entry index.
     */
    protected int $activeUseIndex = 0;
    /**
     * @var int The active Learn-tab entry index.
     */
    protected int $activeLearnIndex = 0;
    /**
     * @var int The active Sort-tab entry index.
     */
    protected int $activeSortIndex = 0;
    /**
     * @var string|null The latest short status message.
     */
    protected ?string $statusMessage = null;
    /**
     * @var MagicSkill|null The spell awaiting a one-target party selection.
     */
    protected ?MagicSkill $pendingUseSpell = null;
    /**
     * @var Character[] The eligible targets for the pending spell.
     */
    protected array $targetCandidates = [];
    /**
     * @var int The active target-candidate index.
     */
    protected int $activeTargetIndex = -1;
    /**
     * @var FieldSkillExecutor|null The shared field-skill execution boundary.
     */
    protected ?FieldSkillExecutor $fieldSkillExecutor = null;

    /**
     * @inheritDoc
     */
    public function enter(): void
    {
        Console::clear();
        $this->getGameScene()->locationHUDWindow->deactivate();
        $this->character ??= $this->getGameScene()->party->leader;
        $this->calculateMargins();
        $this->initializeUI();
        $this->normalizeSelectionIndexes();
        $this->refreshUI();
    }

    /**
     * Calculates the centered menu origin.
     *
     * @return void
     */
    protected function calculateMargins(): void
    {
        $this->leftMargin = max(0, intdiv(get_screen_width() - self::MAGIC_MENU_WIDTH, 2));
        $this->topMargin = max(0, intdiv(get_screen_height() - self::MAGIC_MENU_HEIGHT, 2));
    }

    /**
     * Creates the windows used by the magic screen.
     *
     * @return void
     */
    protected function initializeUI(): void
    {
        $this->borderPack = new DefaultBorderPack();

        $this->summaryPanel = new Window(
            'Magic',
            '',
            new Vector2($this->leftMargin, $this->topMargin),
            self::MAGIC_MENU_WIDTH,
            self::SUMMARY_PANEL_HEIGHT,
            $this->borderPack
        );

        $this->tabPanel = new MagicTabPanel(
            '',
            '',
            new Vector2($this->leftMargin, $this->topMargin + self::SUMMARY_PANEL_HEIGHT),
            self::MAGIC_MENU_WIDTH,
            self::TAB_PANEL_HEIGHT,
            $this->borderPack
        );

        $this->detailPanel = new Window(
            'Details',
            '',
            new Vector2($this->leftMargin, $this->topMargin + self::SUMMARY_PANEL_HEIGHT + self::TAB_PANEL_HEIGHT),
            self::DETAIL_PANEL_WIDTH,
            self::CONTENT_PANEL_HEIGHT,
            $this->borderPack
        );

        $this->listPanel = new MagicListPanel(
            'Use Magic',
            '',
            new Vector2($this->leftMargin + self::DETAIL_PANEL_WIDTH, $this->topMargin + self::SUMMARY_PANEL_HEIGHT + self::TAB_PANEL_HEIGHT),
            self::LIST_PANEL_WIDTH,
            self::CONTENT_PANEL_HEIGHT,
            $this->borderPack
        );

        $this->infoPanel = new Window(
            'Info',
            '',
            new Vector2($this->leftMargin, $this->topMargin + self::SUMMARY_PANEL_HEIGHT + self::TAB_PANEL_HEIGHT + self::CONTENT_PANEL_HEIGHT),
            self::MAGIC_MENU_WIDTH,
            self::INFO_PANEL_HEIGHT,
            $this->borderPack
        );
    }

    /**
     * Keeps all per-tab indexes inside their valid bounds.
     *
     * @return void
     */
    protected function normalizeSelectionIndexes(): void
    {
        $useCount = count($this->character?->spellbook->getLearnedSpells() ?? []);
        $learnCount = count($this->character?->spellbook->getLearnableSpells() ?? []);
        $sortCount = count($this->sortOptions);

        $this->activeUseIndex = $useCount > 0 ? clamp($this->activeUseIndex, 0, $useCount - 1) : -1;
        $this->activeLearnIndex = $learnCount > 0 ? clamp($this->activeLearnIndex, 0, $learnCount - 1) : -1;
        $this->activeSortIndex = $sortCount > 0 ? clamp($this->activeSortIndex, 0, $sortCount - 1) : -1;
    }

    /**
     * Rebuilds and renders every window.
     *
     * @return void
     */
    protected function refreshUI(): void
    {
        $this->summaryPanel?->setContent($this->buildSummaryLines());
        $this->summaryPanel?->render();

        $this->tabPanel?->setTabs($this->tabs, $this->activeTabIndex);

        $this->detailPanel?->setContent($this->buildDetailLines());
        $this->detailPanel?->render();

        $this->listPanel?->setTitle($this->getListPanelTitle());
        $this->listPanel?->setEntries($this->buildListEntries(), $this->getActiveEntryIndex());

        $this->infoPanel?->setHelp($this->getInfoHelpText());
        $this->infoPanel?->setContent($this->buildInfoLines());
        $this->infoPanel?->render();
    }

    /**
     * Builds the summary-panel content.
     *
     * @return string[] The summary lines.
     */
    protected function buildSummaryLines(): array
    {
        if (!$this->character instanceof Character) {
            return array_fill(0, self::SUMMARY_PANEL_HEIGHT - 2, '');
        }

        $learnedCount = count($this->character->spellbook->getLearnedSpells());
        $readyCount = $this->character->spellbook->getReadyToLearnCount($this->character, $this->party, $this->getGameScene()->storyEvents);

        return [
            sprintf(' %s', $this->character->name),
            sprintf(
                ' Lv %-3d  HP %9s / %-9s  MP %5s / %-5s',
                $this->character->level,
                number_format($this->character->effectiveStats->currentHp),
                number_format($this->character->effectiveStats->totalHp),
                number_format($this->character->effectiveStats->currentMp),
                number_format($this->character->effectiveStats->totalMp),
            ),
            sprintf(' Learned Magic: %-3d  Ready to Learn: %-3d', $learnedCount, $readyCount),
            sprintf(' Current Order: %s', $this->character->spellbook->getSortOrder()->value),
            ' ',
        ];
    }

    /**
     * Builds the detail-panel content for the active tab.
     *
     * @return string[] The detail lines.
     */
    protected function buildDetailLines(): array
    {
        $availableLines = self::CONTENT_PANEL_HEIGHT - 2;
        $availableWidth = self::DETAIL_PANEL_WIDTH - 4;
        $sourceLines = $this->isSelectingTarget()
            ? $this->buildTargetDetailLines()
            : match ($this->tabs[$this->activeTabIndex] ?? 'Use') {
                'Use' => $this->buildUseDetailLines(),
                'Learn' => $this->buildLearnDetailLines(),
                'Sort' => $this->buildSortDetailLines(),
                default => [],
            };

        $lines = [];

        foreach ($sourceLines as $line) {
            foreach (explode("\n", wrap_text($line, max(1, $availableWidth))) as $wrappedLine) {
                $lines[] = TerminalText::truncateToWidth($wrappedLine, $availableWidth);
            }
        }

        return array_slice(array_pad($lines, $availableLines, ''), 0, $availableLines);
    }

    /**
     * Builds the detail text for the Use tab.
     *
     * @return string[] The detail lines.
     */
    protected function buildUseDetailLines(): array
    {
        $spell = $this->getActiveUseSpell();

        if (!$spell instanceof MagicSkill) {
            return [
                'No learned magic.',
                '',
                'Use magic will appear here once this character has learned or acquired spells.',
            ];
        }

        return [
            sprintf('%s %s', $spell->icon, $spell->name),
            sprintf('MP Cost : %d', $spell->cost),
            sprintf('Occasion: %s', $this->formatOccasionLabel($spell->occasion)),
            sprintf('Scope   : %s', $spell->scope->side->value),
            '',
            $spell->description,
        ];
    }

    /**
     * Builds target and spell details while a one-target cast is pending.
     *
     * @return string[] The target detail lines.
     */
    protected function buildTargetDetailLines(): array
    {
        $spell = $this->pendingUseSpell;
        $target = $this->targetCandidates[$this->activeTargetIndex] ?? null;

        if (!$spell instanceof MagicSkill || !$target instanceof Character) {
            return ['No eligible target.'];
        }

        return [
            sprintf('%s %s', $spell->icon, $spell->name),
            sprintf('Caster: %s', $this->character?->name ?? 'Unknown'),
            sprintf('Cost  : %d MP', $spell->cost),
            '',
            sprintf('Target: %s', $target->name),
            sprintf('Status: %s', $target->isKnockedOut ? 'Knocked Out' : 'Ready'),
            sprintf(
                'HP    : %s / %s',
                number_format($target->effectiveStats->currentHp),
                number_format($target->effectiveStats->totalHp),
            ),
            sprintf(
                'MP    : %s / %s',
                number_format($target->effectiveStats->currentMp),
                number_format($target->effectiveStats->totalMp),
            ),
        ];
    }

    /**
     * Returns the selected learned spell, if any.
     *
     * @return MagicSkill|null The selected spell.
     */
    protected function getActiveUseSpell(): ?MagicSkill
    {
        return $this->character?->spellbook->getLearnedSpells()[$this->activeUseIndex] ?? null;
    }

    /**
     * Converts an occasion enum into a compact UI label.
     *
     * @param Occasion $occasion The occasion to format.
     * @return string The compact label.
     */
    protected function formatOccasionLabel(Occasion $occasion): string
    {
        return match ($occasion) {
            Occasion::ALWAYS => 'Both',
            Occasion::MENU_SCREEN => 'Field',
            Occasion::BATTLE_SCREEN => 'Battle',
            Occasion::NEVER => 'Locked',
        };
    }

    /**
     * Builds the detail text for the Learn tab.
     *
     * @return string[] The detail lines.
     */
    protected function buildLearnDetailLines(): array
    {
        $learnableSpell = $this->getActiveLearnableSpell();

        if (!$learnableSpell instanceof LearnableSpell || !$this->character instanceof Character) {
            return [
                'No discovered magic.',
                '',
                'Discovered spells and their learning requirements will appear here.',
            ];
        }

        $progress = $learnableSpell->requirement->describeProgress($this->character, $this->party, $learnableSpell->trainingHours);

        return [
            sprintf('%s %s', $learnableSpell->skill->icon, $learnableSpell->skill->name),
            sprintf('Status  : %s', $learnableSpell->getStatusLabel($this->character, $this->party)),
            sprintf('Occasion: %s', $this->formatOccasionLabel($learnableSpell->skill->occasion)),
            $learnableSpell->note !== '' ? sprintf('Source  : %s', $learnableSpell->note) : 'Source  : Unrecorded',
            '',
            $progress !== '' ? $progress : 'No additional requirements.',
            '',
            $learnableSpell->skill->description,
        ];
    }

    /**
     * Returns the selected learnable spell, if any.
     *
     * @return LearnableSpell|null The selected learnable spell.
     */
    protected function getActiveLearnableSpell(): ?LearnableSpell
    {
        return $this->character?->spellbook->getLearnableSpells()[$this->activeLearnIndex] ?? null;
    }

    /**
     * Builds the detail text for the Sort tab.
     *
     * @return string[] The detail lines.
     */
    protected function buildSortDetailLines(): array
    {
        return [
            'Spell Order',
            '',
            sprintf('Current: %s', $this->character?->spellbook->getSortOrder()->value ?? SpellSortOrder::A_TO_Z->value),
            '',
            'A-Z keeps learned magic alphabetical.',
            'Z-A reverses the learned magic list.',
            '',
            'Apply a sort order to reorganize the Use tab.',
        ];
    }

    /**
     * Returns the title for the list panel.
     *
     * @return string The list-panel title.
     */
    protected function getListPanelTitle(): string
    {
        if ($this->isSelectingTarget()) {
            return 'Choose Target';
        }

        return match ($this->tabs[$this->activeTabIndex] ?? 'Use') {
            'Use' => 'Use Magic',
            'Learn' => 'Learn Magic',
            'Sort' => 'Sort Learned Magic',
            default => 'Magic',
        };
    }

    /**
     * Builds the visible list rows for the current tab.
     *
     * @return string[] The list entries.
     */
    protected function buildListEntries(): array
    {
        if ($this->isSelectingTarget()) {
            return $this->buildTargetEntries();
        }

        return match ($this->tabs[$this->activeTabIndex] ?? 'Use') {
            'Use' => $this->buildUseEntries(),
            'Learn' => $this->buildLearnEntries(),
            'Sort' => $this->buildSortEntries(),
            default => [],
        };
    }

    /**
     * Builds the Use-tab list rows.
     *
     * @return string[] The formatted rows.
     */
    protected function buildUseEntries(): array
    {
        $availableWidth = self::LIST_PANEL_WIDTH - 4;
        $entries = [];

        foreach ($this->character?->spellbook->getLearnedSpells() ?? [] as $spell) {
            $label = TerminalText::padRight(sprintf('%s %s', $spell->icon, $spell->name), 44);
            $occasion = TerminalText::padRight($this->formatOccasionLabel($spell->occasion), 8);
            $cost = TerminalText::padLeft(sprintf('%d MP', $spell->cost), 6);
            $entries[] = TerminalText::padRight(
                TerminalText::truncateToWidth(" {$label} {$occasion} {$cost}", $availableWidth),
                $availableWidth
            );
        }

        return $entries;
    }

    /**
     * Builds party rows for the pending one-target cast.
     *
     * @return string[] The formatted target rows.
     */
    protected function buildTargetEntries(): array
    {
        $availableWidth = self::LIST_PANEL_WIDTH - 4;
        $entries = [];

        foreach ($this->targetCandidates as $target) {
            $name = TerminalText::padRight($target->name, 24);
            $hp = TerminalText::padLeft(sprintf(
                'HP %s/%s',
                number_format($target->effectiveStats->currentHp),
                number_format($target->effectiveStats->totalHp),
            ), 18);
            $mp = TerminalText::padLeft(sprintf(
                'MP %s/%s',
                number_format($target->effectiveStats->currentMp),
                number_format($target->effectiveStats->totalMp),
            ), 18);
            $entries[] = TerminalText::padRight(
                TerminalText::truncateToWidth(sprintf(' %s %s %s', $name, $hp, $mp), $availableWidth),
                $availableWidth,
            );
        }

        return $entries;
    }

    /**
     * Builds the Learn-tab list rows.
     *
     * @return string[] The formatted rows.
     */
    protected function buildLearnEntries(): array
    {
        $availableWidth = self::LIST_PANEL_WIDTH - 4;
        $entries = [];

        foreach ($this->character?->spellbook->getLearnableSpells() ?? [] as $learnableSpell) {
            $status = $this->character instanceof Character
                ? $learnableSpell->getStatusLabel($this->character, $this->party)
                : 'Unknown';
            $label = TerminalText::padRight(sprintf('%s %s', $learnableSpell->skill->icon, $learnableSpell->skill->name), 44);
            $statusText = TerminalText::padLeft($status, 12);
            $entries[] = TerminalText::padRight(
                TerminalText::truncateToWidth(" {$label} {$statusText}", $availableWidth),
                $availableWidth
            );
        }

        return $entries;
    }

    /**
     * Builds the Sort-tab list rows.
     *
     * @return string[] The formatted rows.
     */
    protected function buildSortEntries(): array
    {
        $availableWidth = self::LIST_PANEL_WIDTH - 4;
        $currentOrder = $this->character?->spellbook->getSortOrder()->value;
        $entries = [];

        foreach ($this->sortOptions as $option) {
            $marker = $currentOrder === $option ? '[Active]' : '';
            $entries[] = TerminalText::padRight(
                TerminalText::truncateToWidth(sprintf(' %s %s', TerminalText::padRight($option, 6), $marker), $availableWidth),
                $availableWidth
            );
        }

        return $entries;
    }

    /**
     * Returns the current list selection index.
     *
     * @return int The active entry index.
     */
    protected function getActiveEntryIndex(): int
    {
        if ($this->isSelectingTarget()) {
            return $this->activeTargetIndex;
        }

        return match ($this->tabs[$this->activeTabIndex] ?? 'Use') {
            'Use' => $this->activeUseIndex,
            'Learn' => $this->activeLearnIndex,
            'Sort' => $this->activeSortIndex,
            default => 0,
        };
    }

    /**
     * Returns the small help string shown on the info-panel border.
     *
     * @return string The help text.
     */
    protected function getInfoHelpText(): string
    {
        if ($this->isSelectingTarget()) {
            return 'enter:Cast  c:Back';
        }

        return match ($this->tabs[$this->activeTabIndex] ?? 'Use') {
            'Use' => 'enter:Cast  c:Cancel',
            'Learn' => 'enter:Learn  c:Cancel',
            'Sort' => 'enter:Apply  c:Cancel',
            default => 'c:Cancel',
        };
    }

    /**
     * Builds the bottom description and status lines.
     *
     * @return string[] The info-panel lines.
     */
    protected function buildInfoLines(): array
    {
        $availableLines = self::INFO_PANEL_HEIGHT - 2;
        $availableWidth = self::MAGIC_MENU_WIDTH - 4;
        $description = $this->isSelectingTarget()
            ? $this->pendingUseSpell?->description ?? 'Choose a party member.'
            : match ($this->tabs[$this->activeTabIndex] ?? 'Use') {
                'Use' => $this->getActiveUseSpell()?->description ?? 'Review learned magic and cast the spells that work from the field.',
                'Learn' => $this->getActiveLearnableSpell()?->skill->description ?? 'Review discovered spells and what each one requires to learn.',
                'Sort' => 'Reorder learned magic to fit how you like to browse spells.',
                default => '',
            };

        $lines = explode("\n", wrap_text($description, max(1, $availableWidth)));
        $lines = array_slice($lines, 0, $availableLines);

        if ($this->statusMessage !== null) {
            $lines[$availableLines - 1] = TerminalText::truncateToWidth($this->statusMessage, $availableWidth);
        }

        return array_slice(array_pad($lines, $availableLines, ''), 0, $availableLines);
    }

    /**
     * @inheritDoc
     */
    public function execute(?SceneStateContext $context = null): void
    {
        if ($this->isSelectingTarget()) {
            $this->handleTargetSelection();
            return;
        }

        if ($this->handleCharacterCycling()) {
            return;
        }

        $this->handleNavigation();
        $this->handleActions();
    }

    /**
     * Handles character cycling shortcuts.
     *
     * @return bool True when the active character changed.
     */
    protected function handleCharacterCycling(): bool
    {
        if ($this->isNextCharacterRequested()) {
            $this->selectCharacterByOffset(1);
            return true;
        }

        if ($this->isPreviousCharacterRequested()) {
            $this->selectCharacterByOffset(-1);
            return true;
        }

        return false;
    }

    /**
     * Selects a different party member.
     *
     * @param int $offset The relative direction to move.
     * @return void
     */
    protected function selectCharacterByOffset(int $offset): void
    {
        $characters = $this->getGameScene()->party->members->toArray();
        $totalCharacters = count($characters);

        if ($totalCharacters < 2) {
            return;
        }

        $currentIndex = array_search($this->character, $characters, true);
        $currentIndex = $currentIndex === false ? 0 : $currentIndex;
        $nextIndex = wrap($currentIndex + $offset, 0, $totalCharacters - 1);

        $this->character = $characters[$nextIndex];
        $this->statusMessage = null;
        $this->normalizeSelectionIndexes();
        $this->refreshUI();
    }

    /**
     * Handles horizontal tab switching and vertical list movement.
     *
     * @return void
     */
    protected function handleNavigation(): void
    {
        $horizontal = Input::getAxis(AxisName::HORIZONTAL);

        if ($horizontal > 0) {
            play_sound(SystemSound::CURSOR);
            $this->selectTabByOffset(1);
            return;
        }

        if ($horizontal < 0) {
            play_sound(SystemSound::CURSOR);
            $this->selectTabByOffset(-1);
            return;
        }

        $vertical = Input::getAxis(AxisName::VERTICAL);

        if ($vertical > 0) {
            play_sound(SystemSound::CURSOR);
            $this->selectEntryByOffset(1);
            return;
        }

        if ($vertical < 0) {
            play_sound(SystemSound::CURSOR);
            $this->selectEntryByOffset(-1);
        }
    }

    /**
     * Switches to a neighboring tab.
     *
     * @param int $offset The relative direction to move.
     * @return void
     */
    protected function selectTabByOffset(int $offset): void
    {
        $this->activeTabIndex = wrap($this->activeTabIndex + $offset, 0, count($this->tabs) - 1);
        $this->statusMessage = null;
        $this->normalizeSelectionIndexes();
        $this->refreshUI();
    }

    /**
     * Moves the active row inside the current tab.
     *
     * @param int $offset The relative direction to move.
     * @return void
     */
    protected function selectEntryByOffset(int $offset): void
    {
        $entryCount = $this->getCurrentEntryCount();

        if ($entryCount < 1) {
            return;
        }

        match ($this->tabs[$this->activeTabIndex] ?? 'Use') {
            'Use' => $this->activeUseIndex = wrap($this->activeUseIndex + $offset, 0, $entryCount - 1),
            'Learn' => $this->activeLearnIndex = wrap($this->activeLearnIndex + $offset, 0, $entryCount - 1),
            'Sort' => $this->activeSortIndex = wrap($this->activeSortIndex + $offset, 0, $entryCount - 1),
            default => null,
        };

        $this->statusMessage = null;
        $this->refreshUI();
    }

    /**
     * Returns the number of entries in the current tab.
     *
     * @return int The current entry count.
     */
    protected function getCurrentEntryCount(): int
    {
        return count($this->buildListEntries());
    }

    /**
     * Handles confirm and cancel input for the magic screen.
     *
     * @return void
     */
    protected function handleActions(): void
    {
        if (Input::isButtonDown('cancel') || Input::isButtonDown('back')) {
            play_sound(SystemSound::CANCEL);
            $this->statusMessage = null;
            $this->setState($this->getGameScene()->mainMenuState);
            return;
        }

        if (Input::isButtonDown('confirm')) {
            play_sound(SystemSound::CONFIRM);
            $this->confirmCurrentSelection();
        }
    }

    /**
     * Executes the action for the current tab selection.
     *
     * @return void
     */
    protected function confirmCurrentSelection(): void
    {
        match ($this->tabs[$this->activeTabIndex] ?? 'Use') {
            'Use' => $this->useSelectedSpell(),
            'Learn' => $this->learnSelectedSpell(),
            'Sort' => $this->applySelectedSortOrder(),
            default => null,
        };

        $this->normalizeSelectionIndexes();
        $this->refreshUI();
    }

    /**
     * Uses the selected spell if it is valid from the field.
     *
     * @return void
     */
    protected function useSelectedSpell(): void
    {
        $spell = $this->getActiveUseSpell();

        if (!$spell instanceof MagicSkill || !$this->character instanceof Character) {
            $this->statusMessage = 'No learned magic is available.';
            return;
        }

        if (!in_array($spell->occasion, [Occasion::ALWAYS, Occasion::MENU_SCREEN], true)) {
            $this->statusMessage = sprintf('%s can only be used in battle.', $spell->name);
            return;
        }

        if ($this->character->stats->currentMp < $spell->cost) {
            $this->statusMessage = sprintf('Not enough MP for %s.', $spell->name);
            return;
        }

        if (
            $spell->scope->side === ItemScopeSide::ALLY
            && $spell->scope->number === ItemScopeNumber::ONE
        ) {
            $this->beginTargetSelection($spell);
            return;
        }

        $this->commitFieldSpell($spell);
    }

    /**
     * Opens target selection without committing MP or effects.
     */
    protected function beginTargetSelection(MagicSkill $spell): void
    {
        /** @var Character[] $targets */
        $targets = SkillTargetPolicy::filterByStatus(
            $this->party->members->toArray(),
            $spell->scope->status,
        );

        if ($targets === []) {
            $this->statusMessage = sprintf('%s has no eligible target.', $spell->name);
            return;
        }

        $this->pendingUseSpell = $spell;
        $this->targetCandidates = $targets;
        $this->activeTargetIndex = 0;
        $this->statusMessage = null;
    }

    /**
     * Handles target navigation, confirmation and cancellation.
     */
    protected function handleTargetSelection(): void
    {
        if (Input::isButtonDown('cancel') || Input::isButtonDown('back')) {
            play_sound(SystemSound::CANCEL);
            $this->cancelTargetSelection();
            $this->refreshUI();
            return;
        }

        $vertical = Input::getAxis(AxisName::VERTICAL);

        if (abs($vertical) > 0 && $this->targetCandidates !== []) {
            play_sound(SystemSound::CURSOR);
            $offset = $vertical > 0 ? 1 : -1;
            $this->activeTargetIndex = wrap(
                $this->activeTargetIndex + $offset,
                0,
                count($this->targetCandidates) - 1,
            );
            $this->statusMessage = null;
            $this->refreshUI();
            return;
        }

        if (!Input::isButtonDown('confirm')) {
            return;
        }

        $spell = $this->pendingUseSpell;
        $target = $this->targetCandidates[$this->activeTargetIndex] ?? null;

        if (!$spell instanceof MagicSkill || !$target instanceof Character) {
            play_sound(SystemSound::BUZZER);
            $this->statusMessage = 'No eligible target is selected.';
            $this->refreshUI();
            return;
        }

        play_sound(SystemSound::CONFIRM);
        $result = $this->commitFieldSpell($spell, $target);

        if ($result->succeeded) {
            $this->cancelTargetSelection(clearStatus: false);
        }

        $this->normalizeSelectionIndexes();
        $this->refreshUI();
    }

    /**
     * Executes one fully resolved field cast and maps its typed outcome to UI text.
     */
    protected function commitFieldSpell(
        MagicSkill $spell,
        ?Character $selectedTarget = null,
    ): FieldSkillExecutionResult
    {
        if (!$this->character instanceof Character) {
            $result = new FieldSkillExecutionResult(
                false,
                failureReason: FieldSkillFailureReason::INVALID_TARGET,
            );
            $this->statusMessage = 'No caster is selected.';
            return $result;
        }

        $this->fieldSkillExecutor ??= new FieldSkillExecutor();
        $result = $this->fieldSkillExecutor->execute(
            $spell,
            $this->character,
            $this->party,
            $selectedTarget,
        );
        $this->statusMessage = $this->describeFieldSkillResult($spell, $result);

        return $result;
    }

    /**
     * Builds concise player-facing feedback for a field cast.
     */
    protected function describeFieldSkillResult(
        MagicSkill $spell,
        FieldSkillExecutionResult $result,
    ): string
    {
        if ($result->succeeded) {
            $targetNames = implode(', ', array_map(
                static fn(Character $target): string => $target->name,
                $result->targets,
            ));

            return sprintf(
                '%s cast %s on %s.',
                $this->character?->name ?? 'The caster',
                $spell->name,
                $targetNames !== '' ? $targetNames : 'the party',
            );
        }

        return match ($result->failureReason) {
            FieldSkillFailureReason::WRONG_OCCASION => sprintf('%s can only be used in battle.', $spell->name),
            FieldSkillFailureReason::CASTER_KNOCKED_OUT => 'A knocked-out character cannot cast magic.',
            FieldSkillFailureReason::INSUFFICIENT_MP => sprintf('Not enough MP for %s.', $spell->name),
            FieldSkillFailureReason::TARGET_REQUIRED => sprintf('Choose a target for %s.', $spell->name),
            FieldSkillFailureReason::INVALID_TARGET => sprintf('%s cannot target that character.', $spell->name),
            FieldSkillFailureReason::NO_ELIGIBLE_TARGETS => sprintf('%s has no eligible target.', $spell->name),
            FieldSkillFailureReason::NO_EFFECTS => sprintf('%s has no field effect configured.', $spell->name),
            FieldSkillFailureReason::NO_EFFECT => sprintf('%s would have no effect on the selected target.', $spell->name),
            FieldSkillFailureReason::UNSUPPORTED_SCOPE => sprintf('%s cannot target anyone from the field.', $spell->name),
            null => sprintf('%s could not be cast.', $spell->name),
        };
    }

    /**
     * Returns to the spell list without committing a pending cast.
     */
    protected function cancelTargetSelection(bool $clearStatus = true): void
    {
        $this->pendingUseSpell = null;
        $this->targetCandidates = [];
        $this->activeTargetIndex = -1;

        if ($clearStatus) {
            $this->statusMessage = null;
        }
    }

    /**
     * Whether the menu is waiting for a one-target spell choice.
     */
    protected function isSelectingTarget(): bool
    {
        return $this->pendingUseSpell instanceof MagicSkill;
    }

    /**
     * Attempts to learn the selected discovered spell.
     *
     * @return void
     */
    protected function learnSelectedSpell(): void
    {
        $learnableSpell = $this->getActiveLearnableSpell();

        if (!$learnableSpell instanceof LearnableSpell || !$this->character instanceof Character) {
            $this->statusMessage = 'No learnable magic is available.';
            return;
        }

        if ($learnableSpell->isLearned) {
            $this->statusMessage = sprintf('%s already knows %s.', $this->character->name, $learnableSpell->skill->name);
            return;
        }

        if ($this->character->spellbook->learn($learnableSpell, $this->character, $this->party, $this->getGameScene()->storyEvents)) {
            $this->statusMessage = sprintf('%s learned %s.', $this->character->name, $learnableSpell->skill->name);
            return;
        }

        $progress = $learnableSpell->requirement->describeProgress($this->character, $this->party, $learnableSpell->trainingHours);
        $this->statusMessage = $progress !== ''
            ? $progress
            : sprintf('%s still needs more progress.', $learnableSpell->skill->name);
    }

    /**
     * Applies the selected spell sort order.
     *
     * @return void
     */
    protected function applySelectedSortOrder(): void
    {
        if (!$this->character instanceof Character) {
            return;
        }

        $sortOrder = SpellSortOrder::tryFrom($this->sortOptions[$this->activeSortIndex] ?? SpellSortOrder::A_TO_Z->value)
            ?? SpellSortOrder::A_TO_Z;

        $this->character->spellbook->sortLearnedSpells($sortOrder);
        $this->statusMessage = sprintf('Learned magic sorted %s.', $sortOrder->value);
    }

    /**
     * @inheritDoc
     */
    public function resume(): void
    {
        $this->refreshUI();
    }

    /**
     * @inheritDoc
     */
    public function suspend(): void
    {
        $this->exit();
    }
}
