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
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Localization\Vocabulary;
use Ichiloto\Engine\Progression\Mastery\MasteryCatalog;
use Ichiloto\Engine\Progression\Mastery\MasteryLayout;
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
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;

/**
 * One character's Mastery Map: their own skills in the project's regions,
 * what each takes to learn, and which one they learn next. The Terminal
 * shows the map as a list per region; a graphical renderer draws it as a
 * map. Both read one projection and one focus, so a choice means the same
 * thing in either.
 *
 * Every word the player reads follows docs/ui-language-and-presentation.md.
 * The points label ("SP") and its spelled-out name ("Skill Points") are the
 * project's vocabulary (`vocab.mastery.points`, `vocab.mastery.points_name`).
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
        $regionIds = $this->getRegionIds();
        $focused = $this->getFocusedStatus();
        $map = $this->getMap();
        $places = $map === null ? [] : MasteryLayout::place($map, $regionIds);
        $nodes = array_map(fn(MasteryNodeStatus $status): MasteryMenuNode => new MasteryMenuNode(
            $status->node->id, $status->node->skill, max(0, (int)array_search($status->node->region, $regionIds, true)),
            $status->state, $this->describeCardState($status), $status->node->requires,
            isset($places[$status->node->id]) ? ['x' => $places[$status->node->id]['x'], 'y' => $places[$status->node->id]['y']] : null), $statuses);

        return new MasteryMenuContent($actor, $this->getTitle(), $this->getRegionLabels(), $this->regionIndex, array_values($nodes),
            $focused?->node->id, $this->getSummary(), $focused?->node->skill ?? $this->getEmptyText(),
            $focused === null ? [] : $this->getFields($focused), $this->findSkill($focused)?->description ?? '',
            $this->getEmptyText(), $this->getDescription(), $this->statusMessage, infoModel: $this->menuInfoText,
            points: $this->getPointsText(), detailStatus: $focused === null ? [] : $this->describeDetailStatus($focused));
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

    /**
     * Asks the player to confirm learning the focused skill, with what it
     * costs in SP, then makes it the one the character learns next; says
     * why not when it cannot be chosen.
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
        $refusal = match ($focused->state) {
            MasteryNodeState::LEARNED => sprintf('%s has already learned %s.', $character->name, $focused->node->skill),
            MasteryNodeState::STUDYING => sprintf('%s is already learning %s.', $character->name, $focused->node->skill),
            MasteryNodeState::LOCKED => implode(' ', array_map(static fn(string $condition): string => "{$condition}.", $this->describeLock($focused))),
            MasteryNodeState::OPEN => null,
        };
        if ($refusal !== null) {
            play_sound(SystemSound::BUZZER);
            $this->statusMessage = $refusal;
            $this->refreshUI();
            return;
        }
        try {
            if (! confirm(sprintf('Learn %s for %d %s?', $focused->node->skill, $focused->remaining, $this->getPointsLabel()))) {
                return;
            }
            $before = array_map(static fn(Skill $skill): string => $skill->name, [...$character->abilityBook->getLearnedAbilities(),
                ...$character->spellbook->getLearnedSpells()]);
            $progression->choose($character, $focused->node->id);
            play_sound(SystemSound::CONFIRM);
            $after = array_map(static fn(Skill $skill): string => $skill->name, [...$character->abilityBook->getLearnedAbilities(),
                ...$character->spellbook->getLearnedSpells()]);
            $learned = array_values(array_diff($after, $before));
            $this->statusMessage = $learned === []
                ? sprintf('%s is now learning %s.', $character->name, $focused->node->skill)
                : sprintf('%s learned %s!', $character->name, implode(', ', $learned));
        } catch (InvalidArgumentException $refusal) {
            // The menu refuses learned and locked skills itself, so this is the map changing underneath it.
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
        // Name, level and the SP total sit together, as one corner block.
        $identity = sprintf(' %s   %s %d', $this->character->name, Vocabulary::getTerm('stats.level', 'Lv'), $this->character->level);
        if (($points = $this->getPointsText()) !== '') {
            $identity .= '   ' . $points;
        }
        $lines = [$identity];
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
        $lines = [' ' . $focused->node->skill];
        foreach ($this->describeDetailStatus($focused) as $status) {
            foreach (TerminalText::wrapToWidth($status, $width) as $line) {
                $lines[] = ' ' . $line;
            }
        }
        $lines[] = '';
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
        $this->infoPanel?->setHelp($page->total > 2 ? $page->range() : 'enter:Learn  c:Back  tab:Next');

        return array_pad($page->lines, 2, '');
    }

    /** @return array<string, string> The skill being learned, with its progress, and how many are learned. */
    protected function getSummary(): array
    {
        $statuses = $this->describeNodes();
        if ($statuses === []) {
            return [];
        }
        $learning = array_find($statuses, static fn(MasteryNodeStatus $status): bool => $status->state === MasteryNodeState::STUDYING);
        $learned = count(array_filter($statuses, static fn(MasteryNodeStatus $status): bool => $status->state === MasteryNodeState::LEARNED));
        $summary = [];
        if ($learning !== null) {
            $summary['Learning'] = sprintf('%s %d/%d', $learning->node->skill, $learning->progress, $learning->node->cost);
        }
        $summary['Learned'] = sprintf('%d/%d', $learned, count($statuses));

        return $summary;
    }

    /** The character's unspent SP as the corner block shows it ("SP 120"); empty when there is none. */
    protected function getPointsText(): string
    {
        $points = $this->character?->mastery->banked ?? 0;

        return $points > 0 ? sprintf('%s %d', $this->getPointsLabel(), $points) : '';
    }

    /** The project's short label for Mastery points, "SP" unless the project words it otherwise. */
    protected function getPointsLabel(): string
    {
        return Vocabulary::getLabel('mastery.points', 'SP');
    }

    /** The project's spelled-out name for Mastery points, "Skill Points" unless the project words it otherwise. */
    protected function getPointsName(): string
    {
        return Vocabulary::getLabel('mastery.points_name', 'Skill Points');
    }

    /** @return array<string, string> The focused skill's SP cost, then what using it takes and whom it targets. */
    protected function getFields(MasteryNodeStatus $status): array
    {
        $skill = $this->findSkill($status);
        $fields = [];
        if ($status->state === MasteryNodeState::OPEN || $status->state === MasteryNodeState::LOCKED) {
            $fields[$this->getPointsLabel()] = $this->describeCost($status);
        }
        if ($skill !== null) {
            $fields[Vocabulary::getTerm('stats.mp', 'MP') . ' Cost'] = (string)$skill->cost;
            $fields['Target'] = $skill->scope->side->value . ' / ' . $skill->scope->number->value;
        }

        return $fields;
    }

    /** @return list<string> The focused skill's status under its name: Learned, Learning 10/50, or what unlocks it. */
    protected function describeDetailStatus(MasteryNodeStatus $status): array
    {
        return match ($status->state) {
            MasteryNodeState::LEARNED, MasteryNodeState::STUDYING => [$this->describeState($status)],
            MasteryNodeState::OPEN => [],
            MasteryNodeState::LOCKED => $this->describeLock($status),
        };
    }

    /** The node's status in words, as the Terminal list shows it. */
    protected function describeState(MasteryNodeStatus $status): string
    {
        return match ($status->state) {
            MasteryNodeState::LEARNED => 'Learned',
            MasteryNodeState::STUDYING => sprintf('Learning %d/%d', $status->progress, $status->node->cost),
            MasteryNodeState::OPEN => $this->describeCost($status),
            MasteryNodeState::LOCKED => $status->opensAtLevel !== null
                ? sprintf('Unlocks at %s %d', Vocabulary::getTerm('stats.level', 'Lv'), $status->opensAtLevel)
                : 'Locked',
        };
    }

    /** The node's status as a map card has room for it; the card being learned is highlighted. */
    protected function describeCardState(MasteryNodeStatus $status): string
    {
        return match ($status->state) {
            MasteryNodeState::LEARNED => 'Learned',
            MasteryNodeState::STUDYING => sprintf('%d/%d', $status->progress, $status->node->cost),
            MasteryNodeState::OPEN => $this->describeCost($status),
            MasteryNodeState::LOCKED => $status->opensAtLevel !== null
                ? sprintf('%s %d', Vocabulary::getTerm('stats.level', 'Lv'), $status->opensAtLevel)
                : 'Locked',
        };
    }

    /** The SP a skill not yet learned costs, as a bare number, or the SP already in it out of that cost. */
    protected function describeCost(MasteryNodeStatus $status): string
    {
        return $status->progress > 0
            ? sprintf('%d/%d', $status->progress, $status->node->cost)
            : (string)$status->node->cost;
    }

    /** @return list<string> What still unlocks a locked node, one condition each ("Unlocks at Lv 12"). */
    protected function describeLock(MasteryNodeStatus $status): array
    {
        $conditions = [];
        if ($status->opensAtLevel !== null) {
            $conditions[] = sprintf('Unlocks at %s %d', Vocabulary::getTerm('stats.level', 'Lv'), $status->opensAtLevel);
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
        $points = sprintf('%s earned also counts as %s (%s).', Vocabulary::getTerm('stats.exp', 'EXP'), $this->getPointsName(), $this->getPointsLabel());

        return $this->getFocusedStatus() === null ? $points : 'Choose the next skill to learn. ' . $points;
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
