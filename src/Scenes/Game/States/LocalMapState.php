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
use Ichiloto\Engine\UI\Navigation\{DirectionalFocusGraph, FocusDirection};
use Ichiloto\Engine\UI\Windows\Enumerations\WindowHeightPolicy;
use Ichiloto\Engine\UI\Windows\Window;

/** Whole active-map atlas; location names appear only under focus. */
class LocalMapState extends GameSceneState implements \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasProviderInterface
{
    protected ?Window $window = null;
    protected int $selected = 0;
    protected int $detailPage = 0;
    protected int $legendPageSize = 1;
    private array $screenSize = [];

    public function getPresentationCanvas(): ?\Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas
    {
        $canvas = $this->getGameScene()->getMapAtlasCanvas(\Ichiloto\Engine\UI\Presentation\MapAtlasSize::OPENED,
            selected: $this->selected, detailPage: $this->detailPage);
        if ($canvas !== null) { $this->legendPageSize = \Ichiloto\Engine\UI\Presentation\MapAtlasPresentation::getLegendPageSize($canvas); }
        return $canvas;
    }

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
        $vertical = (int) Input::getAxis(AxisName::VERTICAL);
        $horizontal = (int) Input::getAxis(AxisName::HORIZONTAL);
        $direction = 0;
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
        } elseif (($horizontal!==0 || $vertical!==0) && $landmarks!==[]) {
            $positions=[];
            foreach ($landmarks as $index=>$landmark) {
                $point=$landmark['definition']->getMarkerCells()[0];
                $positions['landmark-'.$index]=[$point->x,$point->y];
            }
            $focus=DirectionalFocusGraph::createFromPositions($positions,'landmark-'.$this->selected);
            $axis=$horizontal!==0 ? ($horizontal>0 ? FocusDirection::RIGHT : FocusDirection::LEFT)
                : ($vertical>0 ? FocusDirection::DOWN : FocusDirection::UP);
            if ($focus->moveFocus($axis)) {
                $this->selected=(int)substr($focus->getFocusedNode()->id,strlen('landmark-'));
                $this->detailPage=0;
                $changed=true;
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
        $hints[]=MapAtlasTerminal::getFocusHint();
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
        $mapHeight = $height - 5;
        $map = MapAtlasTerminal::renderMap($overview, $landmarks, $scene->player->position,
            $scene->player->heading, min(512, $width), min(256, $mapHeight), $this->selected);
        $this->legendPageSize = 1;
        $rows = [TerminalText::padCenter('N',$width),...$map];
        $rows[] = TerminalText::truncateToWidth(MessageCatalog::getTerm('map.symbols'), $width);
        $focused=$landmarks[$this->selected] ?? null;
        $detail = $focused!==null ? MapAtlasTerminal::getLandmarkLabel($focused)
            . ($focused['accessible']===false ? ' ['.MessageCatalog::getTerm('map.locked').']' : '') : '';
        $detailLines = $detail === '' ? [] : TerminalText::wrapToWidth($detail, $width);
        $pages = max(1, (int) ceil(count($detailLines) / 2));
        $page = $this->detailPage % $pages;
        $rows[] = ($landmarks===[] ? '' : ($this->selected+1).'/'.count($landmarks))
            . ($pages > 1 ? '  '.MessageCatalog::getTerm('map.details') . ' ' . ($page + 1) . '/' . $pages : '');
        array_push($rows, ...array_map(static fn(string $line)=>SelectionStyle::apply($line),
            array_pad(array_slice($detailLines, $page * 2, 2), 2, '')));
        return array_slice($rows, 0, $height);
    }
}
