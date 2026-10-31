<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\UI\Presentation\TerminalControlText;
use Ichiloto\Engine\Battle\BattleCommandType;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Localization\Vocabulary;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Presentation\{MenuCanvasState, MenuDetailSheet, MenuDetailStyle, MenuDetailViewport, MenuPresentationCatalog, MenuRow,
    MenuRowValue, SummonInfoSheet, SummonsMenuContent, SummonsMenuPresentation, SummonsMenuView};
use Ichiloto\Engine\UI\Text\{TerminalMenuRow, TextPage, TextViewport};
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Window;

/** Owned summons, separate information and holder flows, and the Equipment assignment context. */
class SummonsMenuState extends GameSceneState implements CanvasProviderInterface
{
    use MenuCanvasState;

    protected const int MENU_WIDTH = 110;
    protected const int SUMMARY_PANEL_HEIGHT = 5;
    protected const int LIST_PANEL_HEIGHT = 26;
    protected const int INFO_PANEL_HEIGHT = 4;

    /** A character is present only when Equipment opens this screen. */
    public ?Character $character = null;
    private ?EquipmentMenuState $equipmentReturn = null;
    protected SummonsMenuView $view = SummonsMenuView::SUMMONS;
    /** @var list<SummonCutsceneDefinition> */
    private array $availableSummons = [];
    /** @var list<SummonCutsceneDefinition> */
    protected array $summons = [];
    protected int $activeIndex = 0;
    private ?string $selectedSummonId = null;
    private ?TextViewport $detailViewport = null;
    private ?TextPage $lastDetailPage = null;
    private array $lastRows = [];
    protected ?Window $summaryPanel = null;
    protected ?Window $listPanel = null;
    protected ?Window $infoPanel = null;

    public function openLibrary(): void
    {
        $this->character = null;
        $this->equipmentReturn = null;
    }

    public function openForCharacter(Character $character, EquipmentMenuState $returnState): void
    {
        $this->character = $character;
        $this->equipmentReturn = $returnState;
    }

    /** Availability remains the authored world-state contract; no future eligibility roster is displayed. */
    public static function loadSummons(?GameState $gameState = null, ?Party $party = null): array
    {
        return array_values(array_filter((new SummonCutsceneLibrary())->load(),
            static fn(SummonCutsceneDefinition $definition): bool => $definition->availability === null
                || ($gameState !== null && $definition->isAvailable($gameState, $party))));
    }

    public function enter(): void
    {
        Console::clear();
        $this->resetMenuPresentation();
        $this->getGameScene()->locationHUDWindow->deactivate();
        $this->availableSummons = array_values((new SummonCutsceneLibrary())->load());
        $this->selectedSummonId = null;
        $this->view = $this->character === null ? SummonsMenuView::SUMMONS : SummonsMenuView::CHARACTER;
        $this->activeIndex = 0;
        $this->detailViewport = new TextViewport();
        $this->initializeUI();
        $this->refreshUI();
    }

    protected function initializeUI(): void
    {
        // Like the other reading menus, the panels shrink to a smaller terminal instead of running off it.
        $width = min(self::MENU_WIDTH, max(20, get_screen_width()));
        $listHeight = max(3, min(self::LIST_PANEL_HEIGHT, get_screen_height() - self::SUMMARY_PANEL_HEIGHT - self::INFO_PANEL_HEIGHT));
        $left = max(0, intdiv(get_screen_width() - $width, 2));
        $top = max(0, intdiv(get_screen_height() - self::SUMMARY_PANEL_HEIGHT - $listHeight - self::INFO_PANEL_HEIGHT, 2));
        $border = new DefaultBorderPack();
        $this->summaryPanel = new Window('', '', new Vector2($left, $top), $width, self::SUMMARY_PANEL_HEIGHT, $border);
        $this->listPanel = new Window('', '', new Vector2($left, $top + self::SUMMARY_PANEL_HEIGHT), $width, $listHeight, $border);
        $this->infoPanel = new Window('Info', '', new Vector2($left, $top + self::SUMMARY_PANEL_HEIGHT + $listHeight), $width, self::INFO_PANEL_HEIGHT, $border);
    }

    private function refreshDefinitions(): void
    {
        if ($this->character !== null && !$this->party->participants->contains($this->character)) {
            $this->character = null;
            $this->equipmentReturn = null;
            $this->view = SummonsMenuView::SUMMONS;
        }
        $this->summons = array_values(array_filter($this->availableSummons, function (SummonCutsceneDefinition $definition): bool {
            if ($definition->availability !== null && !$definition->isAvailable($this->getGameScene()->gameState, $this->party)) { return false; }
            return $this->character === null || ($definition->wielders !== null
                && ($this->character->hasSummon($definition->id)
                    || $this->party->canAssignSummon($definition, $this->character, $this->getGameScene()->gameState)));
        }));
        if ($this->selectedSummonId !== null && array_find($this->summons,
            fn(SummonCutsceneDefinition $definition): bool => $definition->id === $this->selectedSummonId) === null) {
            $this->view = $this->character === null ? SummonsMenuView::SUMMONS : SummonsMenuView::CHARACTER;
            $this->selectedSummonId = null;
            $this->activeIndex = 0;
        }
    }

    private function getSelectedSummon(): ?SummonCutsceneDefinition
    {
        return match ($this->view) {
            SummonsMenuView::SUMMONS, SummonsMenuView::CHARACTER => $this->summons[$this->activeIndex] ?? null,
            default => array_find($this->summons, fn(SummonCutsceneDefinition $definition): bool => $definition->id === $this->selectedSummonId),
        };
    }

    /** @return list<Character> Current members that the same atomic Party operation would accept. */
    private function getHolderOptions(): array
    {
        $definition = $this->getSelectedSummon();
        return $definition === null ? [] : array_values(array_filter($this->party->participants->toArray(),
            fn(Character $member): bool => $member->hasSummon($definition->id)
                || $this->party->canReassignSummon($definition, $member, $this->getGameScene()->gameState)));
    }

    /** @return list<MenuRow> */
    private function getRows(): array
    {
        $definition = $this->getSelectedSummon();
        if ($this->view === SummonsMenuView::ACTIONS) {
            $labels = ['info' => 'Info'];
            if ($definition?->wielders !== null && $this->getHolderOptions() !== []) { $labels['assign'] = 'Assign'; }
            $rows = [];
            foreach ($labels as $id => $label) { $rows[] = new MenuRow($id, $label, [new MenuRowValue(''), new MenuRowValue('')]); }
        } elseif ($this->view === SummonsMenuView::HOLDERS) {
            if ($definition === null) { return []; }
            $members = $this->getHolderOptions();
            $rows = array_map(static fn(Character $member): MenuRow => new MenuRow('member-' . ($member->actorId ?? spl_object_id($member)), $member->name,
                [new MenuRowValue($member->role->name), new MenuRowValue($member->hasSummon($definition->id) ? 'Assigned' : '')]), $members);
        } else {
            $rows = array_map(function (SummonCutsceneDefinition $summon): MenuRow {
                $holders = $this->party->getSummonHolders($summon->id);
                $status = $this->character === null ? implode(', ', array_map(static fn(Character $holder): string => $holder->name, $holders))
                    : ($this->character->hasSummon($summon->id) ? 'Assigned' : '');
                return new MenuRow($summon->id, $summon->name, [new MenuRowValue($summon->moveName ?? ''), new MenuRowValue($status)]);
            }, $this->summons);
        }
        return array_map(fn(MenuRow $row, int $index): MenuRow => new MenuRow($row->id, $row->label, $row->values,
            selected: $index === $this->activeIndex, focused: $index === $this->activeIndex && $this->view !== SummonsMenuView::INFO),
            $rows, array_keys($rows));
    }

    public function getPresentationContent(): SummonsMenuContent
    {
        $definition = $this->getSelectedSummon();
        $title = Vocabulary::getTerm('command.' . BattleCommandType::SUMMON->value, 'Summons');
        $summary = $this->character === null ? [] : [$this->character->name];
        if ($this->view === SummonsMenuView::INFO) { $summary = [$title . ' · ' . Vocabulary::getLabel('summons.info', 'Info')]; }
        if (in_array($this->view, [SummonsMenuView::ACTIONS, SummonsMenuView::INFO], true)) { $title = $definition?->name ?? $title; }
        if ($this->view === SummonsMenuView::HOLDERS) { $title = 'Assign / ' . ($definition?->name ?? $title); }
        $info = $this->view === SummonsMenuView::INFO;
        return new SummonsMenuContent($this->character, $title, $summary,
            $this->getRows(), $this->activeIndex, $info, $definition?->description ?? '',
            null, $this->getActionHints(), $info ? $this->getDetailSheet() : null);
    }

    /** @return list<\Ichiloto\Engine\IO\ActionHint> */
    protected function getActionHints(): array
    {
        $label = 'Choose';
        if ($this->view === SummonsMenuView::CHARACTER) {
            $label = $this->character?->hasSummon($this->getSelectedSummon()?->id ?? '') ? 'Release' : 'Assign';
        } elseif ($this->view === SummonsMenuView::HOLDERS) {
            $member = $this->getHolderOptions()[$this->activeIndex] ?? null;
            $label = $member?->hasSummon($this->getSelectedSummon()?->id ?? '') ? 'Release' : 'Assign';
        }
        $hints = [ActionHints::resolve('cancel', $this->view === SummonsMenuView::SUMMONS ? 'Cancel' : 'Back')];
        if ($this->view !== SummonsMenuView::INFO && $this->getRows() !== []) { array_unshift($hints, ActionHints::resolve('confirm', $label)); }
        if ($this->view === SummonsMenuView::INFO) { array_push($hints, ActionHints::resolve('up', 'Scroll up'), ActionHints::resolve('down', 'Scroll down')); }
        if ($this->view === SummonsMenuView::CHARACTER) {
            array_push($hints, ActionHints::resolve('character_next', 'Next', KeyCode::TAB),
                ActionHints::resolve('character_previous', 'Previous', KeyCode::SHIFT_TAB));
        }
        return $hints;
    }

    protected function composeMenuCanvas(MenuPresentationCatalog $theme, float $time): ?PresentationCanvas
    {
        $content = $this->getPresentationContent();
        $page = null;
        if ($content->detailsOpen) {
            $page = $content->details->createPage($this->detailViewport,
                SummonsMenuPresentation::getDetailViewport($content, $theme), $theme->createDetailStyle());
        }
        $canvas = SummonsMenuPresentation::compose($content, $theme, $page, $this->menuInfoText, $time);
        if ($page !== null) { $this->lastDetailPage = $page; }
        return $canvas;
    }

    protected function refreshUI(): void
    {
        $this->refreshDefinitions();
        $this->activeIndex = min($this->activeIndex, max(0, count($this->getRows()) - 1));
        $this->detailViewport ??= new TextViewport();
        $content = $this->getPresentationContent();
        $this->lastRows = $this->getRowSnapshot($content->rows);
        $this->summaryPanel->setContent([$content->title, ...$content->summary]);
        $this->summaryPanel->render();
        if ($content->details !== null) { $this->refreshDetailPanel($content->details); }
        else { $this->refreshListPanel(); }
        $this->infoPanel->setHelp(TerminalControlText::formatHints($content->hints));
        $this->refreshInfoPanel($content->description);
    }

    /** The reading range sits under the description in the Info footer, only while Info overflows. */
    private function refreshInfoPanel(string $description): void
    {
        $page = $this->view === SummonsMenuView::INFO ? $this->lastDetailPage : null;
        $this->infoPanel->setContent([$description, $page !== null && $page->total > count($page->lines)
            ? sprintf('%d-%d / %d', $page->first + 1, $page->first + count($page->lines), $page->total) : '']);
        $this->infoPanel->render();
    }

    private function refreshListPanel(): void
    {
        $rows = $this->getRows();
        $size = $this->listPanel->getContentHeight();
        $offset = max(0, min($this->activeIndex - intdiv($size, 2), count($rows) - $size));
        $lines = [];
        foreach (array_slice($rows, $offset, $size) as $row) {
            $lines[] = TerminalMenuRow::compose($row->label, implode('  ', array_map(static fn(MenuRowValue $value): string => $value->text, $row->values)),
                $this->listPanel->getContentWidth(), prefix: $row->focused ? ' > ' : '   ');
        }
        if ($rows === []) { $lines[] = $this->character === null ? 'No summons.' : 'No assignable summons.'; }
        $this->listPanel->setContent(array_pad($lines, $size, ''));
        $this->listPanel->setHelp(count($rows) > $size ? sprintf('%d–%d / %d', $offset + 1, min(count($rows), $offset + $size), count($rows)) : '');
        $this->listPanel->render();
    }

    protected function refreshDetailPanel(MenuDetailSheet $details): void
    {
        $page = $details->createPage($this->detailViewport, new MenuDetailViewport($this->listPanel->getContentWidth() - 2,
            $this->listPanel->getContentHeight()), MenuDetailStyle::createTerminal());
        $layout = $details->layout($page->columns, MenuDetailStyle::createTerminal());
        $this->listPanel->setContent($layout->getTerminalLines($page, $page->columns));
        $this->listPanel->setHelp('');
        $this->listPanel->render();
        $this->lastDetailPage = $page;
    }

    protected function getDetailSheet(): MenuDetailSheet
    {
        $definition = $this->getSelectedSummon();
        return new MenuDetailSheet($definition === null ? [] : SummonInfoSheet::createSections($definition));
    }

    private function setView(SummonsMenuView $view, int $index = 0): void
    {
        $this->view = $view;
        $this->activeIndex = $index;
        $this->lastDetailPage = null;
        $this->detailViewport?->reset();
        $this->refreshUI();
    }

    public function execute(?SceneStateContext $context = null): void
    {
        $priorCharacter = $this->character;
        $priorView = $this->view;
        $this->refreshDefinitions();
        if ($priorCharacter !== $this->character || $priorView !== $this->view
            || $this->getRowSnapshot($this->getRows()) !== $this->lastRows) {
            // Refresh a changed roster/availability before accepting a choice made against its old rows.
            $this->refreshUI();
            return;
        }
        if ($this->handleCharacterCycling()) { return; }
        $v = Input::getAxis(AxisName::VERTICAL);
        if ($v !== 0.0) {
            if ($this->view === SummonsMenuView::INFO && $this->lastDetailPage !== null) {
                $this->detailViewport->scroll($v > 0 ? 1 : -1, $this->lastDetailPage);
                $this->refreshDetailPanel($this->getDetailSheet());
                $this->refreshInfoPanel($this->getSelectedSummon()?->description ?? '');
            } elseif (($total = count($this->getRows())) > 0) {
                $this->activeIndex = wrap($this->activeIndex + ($v > 0 ? 1 : -1), 0, $total - 1);
                $this->refreshUI();
            }
        }
        $this->handleActions();
    }

    /** A bounded display snapshot; unchanged Terminal frames require no redraw. */
    private function getRowSnapshot(array $rows): array
    {
        return array_map(static fn(MenuRow $row): array => [$row->id, $row->label, array_column($row->values, 'text')], $rows);
    }

    protected function handleActions(): void
    {
        if (Input::isButtonPressed('back') || Input::isButtonPressed('cancel')) {
            match ($this->view) {
                SummonsMenuView::INFO, SummonsMenuView::HOLDERS => $this->setView(SummonsMenuView::ACTIONS),
                SummonsMenuView::ACTIONS => $this->setView(SummonsMenuView::SUMMONS,
                    array_find_key($this->summons, fn($definition): bool => $definition->id === $this->selectedSummonId) ?? 0),
                SummonsMenuView::CHARACTER => $this->setState($this->equipmentReturn ?? $this->getGameScene()->mainMenuState),
                SummonsMenuView::SUMMONS => $this->setState($this->getGameScene()->mainMenuState),
            };
            return;
        }
        if (!Input::isButtonPressed('confirm') || $this->view === SummonsMenuView::INFO) { return; }
        $definition = $this->getSelectedSummon();
        if ($definition === null) { return; }
        if ($this->view === SummonsMenuView::SUMMONS) {
            $this->selectedSummonId = $definition->id;
            $this->setView(SummonsMenuView::ACTIONS);
        } elseif ($this->view === SummonsMenuView::ACTIONS) {
            $action = $this->getRows()[$this->activeIndex]->id ?? null;
            if ($action === 'info') { $this->setView(SummonsMenuView::INFO); }
            elseif ($action === 'assign') { $this->setView(SummonsMenuView::HOLDERS); }
        } else {
            $member = $this->view === SummonsMenuView::CHARACTER ? $this->character : ($this->getHolderOptions()[$this->activeIndex] ?? null);
            if ($member === null || !$this->party->participants->contains($member)) { $this->refreshUI(); return; }
            if ($member->hasSummon($definition->id)) { $this->party->unassignSummon($definition->id, $member); }
            elseif ($this->view === SummonsMenuView::CHARACTER) { $this->party->assignSummon($definition, $member, $this->getGameScene()->gameState); }
            else { $this->party->reassignSummon($definition, $member, $this->getGameScene()->gameState); }
            $this->refreshUI();
        }
    }

    protected function handleCharacterCycling(): bool
    {
        if ($this->view !== SummonsMenuView::CHARACTER) { return false; }
        $offset = $this->isNextCharacterRequested() ? 1 : ($this->isPreviousCharacterRequested() ? -1 : 0);
        $members = $this->party->participants->toArray();
        if ($offset === 0 || count($members) < 2) { return false; }
        $current = array_search($this->character, $members, true);
        $this->character = $members[wrap(($current === false ? 0 : $current) + $offset, 0, count($members) - 1)];
        if ($this->equipmentReturn !== null) { $this->equipmentReturn->character = $this->character; }
        $this->setView(SummonsMenuView::CHARACTER);
        return true;
    }

    public function resume(): void { $this->refreshUI(); }
}
