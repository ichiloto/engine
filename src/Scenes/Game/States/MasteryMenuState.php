<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Menu\MagicMenu\Windows\MagicListPanel;
use Ichiloto\Engine\Core\Menu\MagicMenu\Windows\MagicTabPanel;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Skills\Skill;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Localization\Vocabulary;
use Ichiloto\Engine\Progression\Mastery\MasteryCatalog;
use Ichiloto\Engine\Progression\Mastery\MasteryNodeState;
use Ichiloto\Engine\Progression\Mastery\MasteryNodeStatus;
use Ichiloto\Engine\Progression\Mastery\MasteryProgression;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Presentation\MasteryMenuContent;
use Ichiloto\Engine\UI\Presentation\MasteryMenuNode;
use Ichiloto\Engine\UI\Presentation\MasteryMenuPresentation;
use Ichiloto\Engine\UI\Presentation\MenuCanvasState;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Window;
use InvalidArgumentException;

/**
 * One character's Mastery Map: their own skills in the project's regions,
 * what each needs, and which one they study next. The Terminal shows the
 * map as a list per region; a graphical renderer draws it as a map. Both
 * read one projection and one focus, so a choice means the same thing in
 * either.
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
        $regions = $this->getRegionNames();
        $regionIds = $this->getRegionIds();
        $focused = $this->getFocusedStatus();
        $nodes = array_map(fn(MasteryNodeStatus $status): MasteryMenuNode => new MasteryMenuNode(
            $status->node->id, $status->node->skill, max(0, (int)array_search($status->node->region, $regionIds, true)),
            $status->state, $this->describeState($status), $status->node->requires, $status->node->position), $statuses);

        return new MasteryMenuContent($actor, $this->getTitle(), $regions, $this->regionIndex, array_values($nodes),
            $focused?->node->id, $this->getSummary(), $focused?->node->skill ?? $this->getEmptyText(),
            $focused === null ? [] : $this->getFields($focused), $this->findSkill($focused)?->description ?? '',
            $this->getEmptyText(), $this->getDescription(), $this->statusMessage, infoModel: $this->menuInfoText);
    }

    public function enter(): void
    {
        $this->resetMenuPresentation();
        Console::clear();
        $this->getGameScene()->locationHUDWindow->deactivate();
        $this->character ??= $this->getGameScene()->party->leader;
        $this->statusMessage = null;
        $this->initializeUI();
        $this->normalizeFocus();
        $this->refreshUI();
    }

    public function execute(?SceneStateContext $context = null): void
    {
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
            $this->studyFocusedNode();
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

    /** Makes the focused node the one the character studies, saying why not when it cannot be. */
    protected function studyFocusedNode(): void
    {
        $focused = $this->getFocusedStatus();
        $progression = $this->getProgression();
        if ($focused === null || $progression === null || ! $this->character instanceof Character) {
            play_sound(SystemSound::BUZZER);
            return;
        }
        if ($focused->state === MasteryNodeState::STUDYING) {
            play_sound(SystemSound::BUZZER);
            $this->statusMessage = sprintf('%s already studies %s.', $this->character->name, $focused->node->skill);
            $this->refreshUI();
            return;
        }
        try {
            $before = array_map(static fn(Skill $skill): string => $skill->name, [...$this->character->abilityBook->getLearnedAbilities(),
                ...$this->character->spellbook->getLearnedSpells()]);
            $progression->choose($this->character, $focused->node->id);
            play_sound(SystemSound::CONFIRM);
            $after = array_map(static fn(Skill $skill): string => $skill->name, [...$this->character->abilityBook->getLearnedAbilities(),
                ...$this->character->spellbook->getLearnedSpells()]);
            $learned = array_values(array_diff($after, $before));
            $this->statusMessage = $learned === []
                ? sprintf('%s now studies %s.', $this->character->name, $focused->node->skill)
                : sprintf('%s learned %s!', $this->character->name, implode(', ', $learned));
        } catch (InvalidArgumentException $refusal) {
            play_sound(SystemSound::BUZZER);
            $this->statusMessage = $this->describeLock($focused) ?? $refusal->getMessage();
        }
        $this->refreshUI();
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
        $this->tabPanel?->setTabs($this->getRegionNames(), $this->regionIndex);
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
        $lines = [sprintf(' %s   %s %d', $this->character->name, Vocabulary::getTerm('stats.level', 'Lv'), $this->character->level)];
        foreach ($this->getSummary() as $label => $value) {
            $lines[] = sprintf(' %s: %s', $label, $value);
        }

        return array_pad(array_slice($lines, 0, self::SUMMARY_HEIGHT - 2), self::SUMMARY_HEIGHT - 2, '');
    }

    /** @return list<string> */
    protected function buildListEntries(): array
    {
        $width = self::MENU_WIDTH - self::DETAIL_WIDTH - 6;
        return array_map(function (MasteryNodeStatus $status) use ($width): string {
            $value = $this->describeState($status);
            $name = TerminalText::truncateToWidth($status->node->skill, max(1, $width - TerminalText::displayWidth($value) - 2));
            return TerminalText::padRight($name, max(1, $width - TerminalText::displayWidth($value))) . $value;
        }, $this->getRegionStatuses());
    }

    /** @return list<string> */
    protected function buildDetailLines(): array
    {
        $width = self::DETAIL_WIDTH - 4;
        $focused = $this->getFocusedStatus();
        if ($focused === null) {
            return [' ' . $this->getEmptyText()];
        }
        $lines = [' ' . $focused->node->skill, ''];
        foreach ($this->getFields($focused) as $label => $value) {
            foreach (TerminalText::wrapToWidth(sprintf('%s: %s', $label, $value), $width) as $line) {
                $lines[] = ' ' . $line;
            }
        }
        $description = $this->findSkill($focused)?->description ?? '';
        if ($description !== '') {
            $lines[] = '';
            foreach (TerminalText::wrapToWidth($description, $width) as $line) {
                $lines[] = ' ' . $line;
            }
        }

        return array_slice($lines, 0, self::CONTENT_HEIGHT - 2);
    }

    /** @return list<string> */
    protected function buildInfoLines(): array
    {
        $page = $this->menuInfoText->getPage($this->getDescription(), $this->statusMessage,
            max(1, ($this->infoPanel?->getContentWidth() ?? self::MENU_WIDTH - 2) - 2));
        $this->infoPanel?->setHelp($page->total > 2 ? $page->range() : 'enter:Study  c:Back  tab:Next');

        return array_pad($page->lines, 2, '');
    }

    /** @return array<string, string> What the character studies, and EXP banked for later. */
    protected function getSummary(): array
    {
        $statuses = $this->describeNodes();
        if ($statuses === []) {
            return [];
        }
        $studying = array_find($statuses, static fn(MasteryNodeStatus $status): bool => $status->state === MasteryNodeState::STUDYING);
        $learned = count(array_filter($statuses, static fn(MasteryNodeStatus $status): bool => $status->state === MasteryNodeState::LEARNED));
        $summary = [
            'Studying' => $studying === null ? 'Nothing open yet' : sprintf('%s (%d / %d EXP)', $studying->node->skill, $studying->progress, $studying->node->cost),
            'Learned' => sprintf('%d of %d', $learned, count($statuses)),
        ];
        if (($this->character?->mastery->banked ?? 0) > 0) {
            $summary['Banked'] = sprintf('%d EXP for the next skill that opens', $this->character->mastery->banked);
        }

        return $summary;
    }

    /** @return array<string, string> */
    protected function getFields(MasteryNodeStatus $status): array
    {
        $skill = $this->findSkill($status);
        $fields = ['Region' => $this->getRegionNames()[(int)array_search($status->node->region, $this->getRegionIds(), true)] ?? $status->node->region,
            'State' => $this->describeState($status)];
        if ($status->state !== MasteryNodeState::LEARNED) {
            $fields['To learn'] = sprintf('%d / %d EXP', $status->progress, $status->node->cost);
        }
        if (($lock = $this->describeLock($status)) !== null) {
            $fields['Needs'] = $lock;
        }
        if ($skill !== null) {
            $fields[Vocabulary::getTerm('stats.mp', 'MP') . ' Cost'] = (string)$skill->cost;
            $fields['Target'] = $skill->scope->side->value . ' / ' . $skill->scope->number->value;
        }

        return $fields;
    }

    /** The node's state in words, as the list and the map both show it. */
    protected function describeState(MasteryNodeStatus $status): string
    {
        return match ($status->state) {
            MasteryNodeState::LEARNED => 'Learned',
            MasteryNodeState::STUDYING => sprintf('Studying %d / %d', $status->progress, $status->node->cost),
            MasteryNodeState::OPEN => $status->progress > 0 ? sprintf('Open %d / %d', $status->progress, $status->node->cost) : 'Open',
            MasteryNodeState::LOCKED => $status->opensAtLevel !== null
                ? sprintf('Opens at %s %d', Vocabulary::getTerm('stats.level', 'Lv'), $status->opensAtLevel)
                : 'Locked',
        };
    }

    /** What a locked node still waits for, in words; null when nothing does. */
    protected function describeLock(MasteryNodeStatus $status): ?string
    {
        $parts = [];
        if ($status->opensAtLevel !== null) {
            $parts[] = sprintf('%s %d', Vocabulary::getTerm('stats.level', 'Lv'), $status->opensAtLevel);
        }
        $map = $this->getMap();
        foreach ($status->missing as $required) {
            $parts[] = $map?->findNode($required)?->skill ?? $required;
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    protected function getDescription(): string
    {
        return $this->getFocusedStatus() === null
            ? 'Each character studies one skill at a time; the EXP they earn also goes into it.'
            : 'Choose which skill this character studies next. Each skill keeps the progress it has.';
    }

    protected function getEmptyText(): string
    {
        return $this->getProgression() === null ? 'This character has no Mastery Map.' : 'No skills in this region.';
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

    /** Opens on the region of the skill being studied, so the player sees where they stand. */
    protected function normalizeFocus(): void
    {
        $regions = $this->getRegionIds();
        if ($this->nodeIndexes === [] && ($studying = array_find($this->describeNodes(),
            static fn(MasteryNodeStatus $status): bool => $status->state === MasteryNodeState::STUDYING)) !== null) {
            $this->regionIndex = max(0, (int)array_search($studying->node->region, $regions, true));
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

    /** @return list<string> */
    protected function getRegionNames(): array
    {
        return array_map(static fn($region): string => $region->name, MasteryCatalog::getProjectCatalog()->regions);
    }

    protected function getProgression(): ?MasteryProgression
    {
        return $this->character === null ? null : MasteryProgression::forCharacter($this->character);
    }

    protected function getMap(): ?\Ichiloto\Engine\Progression\Mastery\MasteryMap
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
