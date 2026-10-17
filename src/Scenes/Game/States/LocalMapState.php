<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Localization\MessageCatalog;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Presentation\MapAtlasTerminal;
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowHeightPolicy;
use Ichiloto\Engine\UI\Windows\Window;

/** Whole active-map atlas, with physical positions and a selectable, paged legend. */
class LocalMapState extends GameSceneState
{
    protected ?Window $window = null;
    protected int $selected = 0;
    protected int $detailPage = 0;
    protected int $legendPageSize = 1;
    private array $screenSize = [];

    public function enter(): void
    {
        InputManager::consumeCurrentInput();
        $this->selected = $this->detailPage = 0;
        $this->getGameScene()->locationHUDWindow?->deactivate();
        Console::clear();
        $this->refreshUI();
    }

    public function exit(): void
    {
        Console::clear();
        $this->getGameScene()->locationHUDWindow?->activate();
    }

    public function execute(?SceneStateContext $context = null): void
    {
        $scene = $this->getGameScene();
        if (Input::isButtonPressed('back') || Input::isButtonPressed('cancel') || Input::isButtonPressed('map')) {
            InputManager::consumeCurrentInput();
            $this->setState($scene->fieldState);
            return;
        }
        if (Input::isButtonPressed('map_view')) {
            InputManager::consumeCurrentInput();
            $this->setState($scene->regionMapState);
            return;
        }
        $landmarks = $this->getVisibleLandmarks();
        $direction = (int) Input::getAxis(AxisName::VERTICAL);
        if (Input::isButtonPressed('menu_page_next')) { $direction = $this->legendPageSize; }
        elseif (Input::isButtonPressed('menu_page_previous')) { $direction = -$this->legendPageSize; }
        $changed = false;
        if ($direction !== 0 && $landmarks !== []) {
            $selected = max(0, min(count($landmarks) - 1, $this->selected + $direction));
            if ($selected !== $this->selected) {
                $this->selected = $selected;
                $this->detailPage = 0;
                $changed = true;
            }
        }
        if (Input::isButtonPressed('info')) { $this->detailPage++; $changed = true; }
        if ($changed || $this->screenSize !== [get_screen_width(), get_screen_height()]) { $this->refreshUI(); }
    }

    protected function getVisibleLandmarks(): array
    {
        $scene = $this->getGameScene();
        return $scene->gameState === null ? [] : ($scene->mapManager?->mapKnowledge?->getVisibleLandmarks(
            $scene->gameState, $scene->party) ?? []);
    }

    protected function refreshUI(): void
    {
        $size = [get_screen_width(), get_screen_height()];
        if ($this->window === null || $size !== $this->screenSize) {
            $this->screenSize = $size;
            $this->window = new Window($this->getGameScene()->party->location->name, '', new Vector2(0, 0),
                max(4, $size[0]), max(4, $size[1]), heightPolicy: WindowHeightPolicy::FIXED);
        }
        $hints = [];
        foreach (['map_view' => MessageCatalog::getTerm('map.region'), 'info' => MessageCatalog::getTerm('map.details'),
            'back' => MessageCatalog::getTerm('map.close')] as $action => $label) {
            $hint = ActionHints::resolve($action, $label);
            $hints[] = ($hint->control?->label ?? 'Unbound') . ':' . $label;
        }
        Console::recomposeFrame(function () use ($hints): void {
            $this->window->setHelp(implode('  ', $hints));
            $this->window->setContent($this->buildContent($this->window->getContentWidth(), $this->window->getContentHeight()));
            $this->window->render();
        });
    }

    /** @return list<string> */
    protected function buildContent(int $width, int $height): array
    {
        if ($width < 12 || $height < 6) {
            return [TerminalText::truncateToWidth(MessageCatalog::getTerm('map.small'), max(0, $width))];
        }
        $scene = $this->getGameScene();
        $overview = $scene->mapManager?->overview;
        if ($overview === null || $scene->player === null) { return [MessageCatalog::getTerm('map.unavailable')]; }
        $landmarks = $this->getVisibleLandmarks();
        $this->selected = max(0, min(count($landmarks) - 1, $this->selected));
        $legendWidth = $width >= 64 ? min(40, intdiv($width, 3)) : $width;
        $mapWidth = $width >= 64 ? $width - $legendWidth - 3 : $width;
        $mapHeight = $width >= 64 ? $height - 4 : max(1, intdiv($height - 4, 2));
        $legendHeight = $width >= 64 ? $mapHeight : max(1, $height - $mapHeight - 4);
        $map = MapAtlasTerminal::renderMap($overview, $landmarks, $scene->player->position,
            $scene->player->heading, min(512, $mapWidth), min(256, $mapHeight), $this->selected);
        $this->legendPageSize = max(1, $legendHeight - 1);
        $start = intdiv($this->selected, $this->legendPageSize) * $this->legendPageSize;
        $legend = [MessageCatalog::getTerm('map.legend') . ($landmarks === [] ? '' : ' ' . ($this->selected + 1) . '/' . count($landmarks))];
        foreach (array_slice($landmarks, $start, max(0, $legendHeight - 1), true) as $index => $landmark) {
            $text = MapAtlasTerminal::getMarkerSymbol($landmark['definition']->kind) . ' ' . MapAtlasTerminal::getLandmarkLabel($landmark)
                . ($landmark['accessible'] === false ? ' [' . MessageCatalog::getTerm('map.locked') . ']' : '');
            $text = TerminalText::truncateToWidth($text, $legendWidth);
            $legend[] = $index === $this->selected ? SelectionStyle::apply($text) : $text;
        }
        $rows = [];
        if ($width >= 64) {
            foreach ($map as $row => $line) { $rows[] = TerminalText::padRight($line, $mapWidth) . ' | ' . ($legend[$row] ?? ''); }
        } else { $rows = [...$map, ...array_pad($legend, $legendHeight, '')]; }
        $rows[] = TerminalText::truncateToWidth(MessageCatalog::getTerm('map.symbols'), $width);
        $detail = isset($landmarks[$this->selected]) ? MapAtlasTerminal::getLandmarkLabel($landmarks[$this->selected]) : '';
        $detailLines = $detail === '' ? [] : TerminalText::wrapToWidth($detail, $width);
        $pages = max(1, (int) ceil(count($detailLines) / 2));
        $this->detailPage %= $pages;
        $rows[] = $pages > 1 ? MessageCatalog::getTerm('map.details') . ' ' . ($this->detailPage + 1) . '/' . $pages : '';
        array_push($rows, ...array_pad(array_slice($detailLines, $this->detailPage * 2, 2), 2, ''));
        return array_slice($rows, 0, $height);
    }
}
