<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Core\Menu\AbilityMenu\Windows\AbilityListPanel;
use Ichiloto\Engine\Core\Menu\AbilityMenu\Windows\AbilityTabPanel;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Abilities\AbilitySortOrder;
use Ichiloto\Engine\Entities\Abilities\LearnableAbility;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Displays the field ability-management screen for a single party member.
 *
 * @package Ichiloto\Engine\Scenes\Game\States
 */
class AbilityMenuState extends GameSceneState
{
    protected const int ABILITY_MENU_WIDTH = 110;
    protected const int ABILITY_MENU_HEIGHT = 35;
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
     * @var string[] The tab labels for the ability screen.
     */
    protected array $tabs = ['Ready', 'Learn', 'Sort'];
    /**
     * @var string[] The available sort-order labels.
     */
    protected array $sortOptions = [
        AbilitySortOrder::A_TO_Z->value,
        AbilitySortOrder::Z_TO_A->value,
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
     * @var AbilityTabPanel|null The tab strip.
     */
    protected ?AbilityTabPanel $tabPanel = null;
    /**
     * @var Window|null The left-side detail panel.
     */
    protected ?Window $detailPanel = null;
    /**
     * @var AbilityListPanel|null The right-side list panel.
     */
    protected ?AbilityListPanel $listPanel = null;
    /**
     * @var Window|null The bottom description and status panel.
     */
    protected ?Window $infoPanel = null;
    /**
     * @var int The active tab index.
     */
    protected int $activeTabIndex = 0;
    /**
     * @var int The active Ready-tab entry index.
     */
    protected int $activeReadyIndex = 0;
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
        $this->leftMargin = max(0, intdiv(get_screen_width() - self::ABILITY_MENU_WIDTH, 2));
        $this->topMargin = max(0, intdiv(get_screen_height() - self::ABILITY_MENU_HEIGHT, 2));
    }

    /**
     * Creates the windows used by the abilities screen.
     *
     * @return void
     */
    protected function initializeUI(): void
    {
        $this->borderPack = new DefaultBorderPack();

        $this->summaryPanel = new Window(
            'Abilities',
            '',
            new Vector2($this->leftMargin, $this->topMargin),
            self::ABILITY_MENU_WIDTH,
            self::SUMMARY_PANEL_HEIGHT,
            $this->borderPack
        );

        $this->tabPanel = new AbilityTabPanel(
            '',
            '',
            new Vector2($this->leftMargin, $this->topMargin + self::SUMMARY_PANEL_HEIGHT),
            self::ABILITY_MENU_WIDTH,
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

        $this->listPanel = new AbilityListPanel(
            'Ready Abilities',
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
            self::ABILITY_MENU_WIDTH,
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
        $readyCount = count($this->character?->abilityBook->getLearnedAbilities() ?? []);
        $learnCount = count($this->character?->abilityBook->getLearnableAbilities() ?? []);
        $sortCount = count($this->sortOptions);

        $this->activeReadyIndex = $readyCount > 0 ? clamp($this->activeReadyIndex, 0, $readyCount - 1) : -1;
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

        $learnedCount = count($this->character->abilityBook->getLearnedAbilities());
        $readyCount = $this->character->abilityBook->getReadyToLearnCount(
            $this->character,
            $this->party,
            $this->getGameScene()->storyEvents,
            $this->getElapsedPlayTime()
        );

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
            sprintf(' Learned Abilities: %-3d  Ready to Learn: %-3d', $learnedCount, $readyCount),
            sprintf(' Current Order: %s', $this->character->abilityBook->getSortOrder()->value),
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
        $sourceLines = match ($this->tabs[$this->activeTabIndex] ?? 'Ready') {
            'Ready' => $this->buildReadyDetailLines(),
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
     * Builds the detail text for the Ready tab.
     *
     * @return string[] The detail lines.
     */
    protected function buildReadyDetailLines(): array
    {
        $ability = $this->getActiveReadyAbility();

        if (!$ability instanceof SpecialSkill) {
            return [
                'No learned abilities.',
                '',
                'Battle abilities will appear here once this character has unlocked or learned them.',
            ];
        }

        return [
            sprintf('%s %s', $ability->icon, $ability->name),
            sprintf('MP Cost : %d', $ability->cost),
            sprintf('Occasion: %s', $this->formatOccasionLabel($ability->occasion)),
            sprintf('Scope   : %s', $ability->scope->side->value),
            '',
            $ability->description,
        ];
    }

    /**
     * Returns the selected learned ability, if any.
     *
     * @return SpecialSkill|null The selected ability.
     */
    protected function getActiveReadyAbility(): ?SpecialSkill
    {
        return $this->character?->abilityBook->getLearnedAbilities()[$this->activeReadyIndex] ?? null;
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
        $learnableAbility = $this->getActiveLearnableAbility();

        if (!$learnableAbility instanceof LearnableAbility || !$this->character instanceof Character) {
            return [
                'No discovered abilities.',
                '',
                'Discovered abilities and their unlock requirements will appear here.',
            ];
        }

        $progress = $learnableAbility->requirement->describeProgress(
            $this->character,
            $this->party,
            $this->getGameScene()->storyEvents,
            $this->getElapsedPlayTime()
        );

        return [
            sprintf('%s %s', $learnableAbility->skill->icon, $learnableAbility->skill->name),
            sprintf(
                'Status  : %s',
                $learnableAbility->getStatusLabel(
                    $this->character,
                    $this->party,
                    $this->getGameScene()->storyEvents,
                    $this->getElapsedPlayTime()
                )
            ),
            sprintf('Occasion: %s', $this->formatOccasionLabel($learnableAbility->skill->occasion)),
            $learnableAbility->note !== '' ? sprintf('Source  : %s', $learnableAbility->note) : 'Source  : Unrecorded',
            '',
            $progress !== '' ? $progress : 'No additional requirements.',
            '',
            $learnableAbility->skill->description,
        ];
    }

    /**
     * Returns the selected learnable ability, if any.
     *
     * @return LearnableAbility|null The selected ability.
     */
    protected function getActiveLearnableAbility(): ?LearnableAbility
    {
        return $this->character?->abilityBook->getLearnableAbilities()[$this->activeLearnIndex] ?? null;
    }

    /**
     * Builds the detail text for the Sort tab.
     *
     * @return string[] The detail lines.
     */
    protected function buildSortDetailLines(): array
    {
        return [
            'Ability Order',
            '',
            sprintf('Current: %s', $this->character?->abilityBook->getSortOrder()->value ?? AbilitySortOrder::A_TO_Z->value),
            '',
            'A-Z keeps learned abilities alphabetical.',
            'Z-A reverses the learned ability list.',
            '',
            'Apply a sort order to reorganize the Ready tab.',
        ];
    }

    /**
     * Returns the title for the list panel.
     *
     * @return string The list-panel title.
     */
    protected function getListPanelTitle(): string
    {
        return match ($this->tabs[$this->activeTabIndex] ?? 'Ready') {
            'Ready' => 'Ready Abilities',
            'Learn' => 'Learn Abilities',
            'Sort' => 'Sort Learned Abilities',
            default => 'Abilities',
        };
    }

    /**
     * Builds the visible list rows for the current tab.
     *
     * @return string[] The list entries.
     */
    protected function buildListEntries(): array
    {
        return match ($this->tabs[$this->activeTabIndex] ?? 'Ready') {
            'Ready' => $this->buildReadyEntries(),
            'Learn' => $this->buildLearnEntries(),
            'Sort' => $this->buildSortEntries(),
            default => [],
        };
    }

    /**
     * Builds the Ready-tab list rows.
     *
     * @return string[] The formatted rows.
     */
    protected function buildReadyEntries(): array
    {
        $availableWidth = self::LIST_PANEL_WIDTH - 4;
        $entries = [];

        foreach ($this->character?->abilityBook->getLearnedAbilities() ?? [] as $ability) {
            $label = TerminalText::padRight(sprintf('%s %s', $ability->icon, $ability->name), 44);
            $occasion = TerminalText::padRight($this->formatOccasionLabel($ability->occasion), 8);
            $cost = TerminalText::padLeft(sprintf('%d MP', $ability->cost), 6);
            $entries[] = TerminalText::padRight(
                TerminalText::truncateToWidth(" {$label} {$occasion} {$cost}", $availableWidth),
                $availableWidth
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

        foreach ($this->character?->abilityBook->getLearnableAbilities() ?? [] as $learnableAbility) {
            $status = $this->character instanceof Character
                ? $learnableAbility->getStatusLabel(
                    $this->character,
                    $this->party,
                    $this->getGameScene()->storyEvents,
                    $this->getElapsedPlayTime()
                )
                : 'Unknown';
            $label = TerminalText::padRight(sprintf('%s %s', $learnableAbility->skill->icon, $learnableAbility->skill->name), 44);
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
        $currentOrder = $this->character?->abilityBook->getSortOrder()->value;
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
        return match ($this->tabs[$this->activeTabIndex] ?? 'Ready') {
            'Ready' => $this->activeReadyIndex,
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
        return match ($this->tabs[$this->activeTabIndex] ?? 'Ready') {
            'Ready' => 'enter:View  c:Cancel',
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
        $availableWidth = self::ABILITY_MENU_WIDTH - 4;
        $description = match ($this->tabs[$this->activeTabIndex] ?? 'Ready') {
            'Ready' => $this->getActiveReadyAbility()?->description ?? 'Review the abilities this character can use in battle.',
            'Learn' => $this->getActiveLearnableAbility()?->skill->description ?? 'Review discovered abilities and what each one requires to learn.',
            'Sort' => 'Reorder learned abilities to fit how you like to browse battle commands.',
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
            $this->selectTabByOffset(1);
            return;
        }

        if ($horizontal < 0) {
            $this->selectTabByOffset(-1);
            return;
        }

        $vertical = Input::getAxis(AxisName::VERTICAL);

        if ($vertical > 0) {
            $this->selectEntryByOffset(1);
            return;
        }

        if ($vertical < 0) {
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

        match ($this->tabs[$this->activeTabIndex] ?? 'Ready') {
            'Ready' => $this->activeReadyIndex = wrap($this->activeReadyIndex + $offset, 0, $entryCount - 1),
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
     * Handles confirm and cancel input for the abilities screen.
     *
     * @return void
     */
    protected function handleActions(): void
    {
        if (Input::isButtonDown('cancel') || Input::isButtonDown('back')) {
            $this->statusMessage = null;
            $this->setState($this->getGameScene()->mainMenuState);
            return;
        }

        if (Input::isButtonDown('confirm')) {
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
        match ($this->tabs[$this->activeTabIndex] ?? 'Ready') {
            'Ready' => $this->reviewSelectedAbility(),
            'Learn' => $this->learnSelectedAbility(),
            'Sort' => $this->applySelectedSortOrder(),
            default => null,
        };

        $this->normalizeSelectionIndexes();
        $this->refreshUI();
    }

    /**
     * Shows a short status for the selected battle ability.
     *
     * @return void
     */
    protected function reviewSelectedAbility(): void
    {
        $ability = $this->getActiveReadyAbility();

        if (!$ability instanceof SpecialSkill) {
            $this->statusMessage = 'No learned abilities are available.';
            return;
        }

        $this->statusMessage = sprintf('%s is ready for battle.', $ability->name);
    }

    /**
     * Attempts to learn the selected discovered ability.
     *
     * @return void
     */
    protected function learnSelectedAbility(): void
    {
        $learnableAbility = $this->getActiveLearnableAbility();

        if (!$learnableAbility instanceof LearnableAbility || !$this->character instanceof Character) {
            $this->statusMessage = 'No learnable abilities are available.';
            return;
        }

        if ($learnableAbility->isLearned) {
            $this->statusMessage = sprintf('%s already knows %s.', $this->character->name, $learnableAbility->skill->name);
            return;
        }

        if (
            $this->character->abilityBook->learn(
                $learnableAbility,
                $this->character,
                $this->party,
                $this->getGameScene()->storyEvents,
                $this->getElapsedPlayTime()
            )
        ) {
            $this->statusMessage = sprintf('%s learned %s.', $this->character->name, $learnableAbility->skill->name);
            return;
        }

        $progress = $learnableAbility->requirement->describeProgress(
            $this->character,
            $this->party,
            $this->getGameScene()->storyEvents,
            $this->getElapsedPlayTime()
        );
        $this->statusMessage = $progress !== ''
            ? $progress
            : sprintf('%s still needs more progress.', $learnableAbility->skill->name);
    }

    /**
     * Applies the selected ability sort order.
     *
     * @return void
     */
    protected function applySelectedSortOrder(): void
    {
        if (!$this->character instanceof Character) {
            return;
        }

        $sortOrder = AbilitySortOrder::tryFrom($this->sortOptions[$this->activeSortIndex] ?? AbilitySortOrder::A_TO_Z->value)
            ?? AbilitySortOrder::A_TO_Z;

        $this->character->abilityBook->sortLearnedAbilities($sortOrder);
        $this->statusMessage = sprintf('Learned abilities sorted %s.', $sortOrder->value);
    }

    /**
     * Returns the elapsed play time in seconds.
     *
     * @return int The elapsed play time.
     */
    protected function getElapsedPlayTime(): int
    {
        return max(0, intval(Time::getTime()));
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
