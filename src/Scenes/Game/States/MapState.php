<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Field\RegionMap;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Localization\MessageCatalog;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Presentation\MapConnectionDiagram;
use Ichiloto\Engine\UI\Presentation\MapAtlasTerminal;
use Ichiloto\Engine\UI\Navigation\{DirectionalFocusGraph, FocusDirection};
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowHeightPolicy;
use Ichiloto\Engine\UI\Windows\Window;

/** Connected places, with one block per place and the focused name below. */
class MapState extends GameSceneState
{
    protected ?Window $mapPanel = null;
    protected ?Window $infoPanel = null;
    protected ?string $selectedId = null;
    protected int $detailPage = 0;
    protected int $viewX = 0;
    protected int $viewY = 0;
    private array $screenSize = [];

    public function enter(): void
    {
        InputManager::consumeCurrentInput();
        $this->selectedId = $this->currentMapId();
        $this->detailPage = $this->viewX = $this->viewY = 0;
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
        if (Input::isButtonPressed('back') || Input::isButtonPressed('cancel') || Input::isButtonPressed('map')) {
            InputManager::consumeCurrentInput();
            $this->setState($this->getGameScene()->fieldState);
            return;
        }
        if (Input::isButtonPressed('map_view')) {
            InputManager::consumeCurrentInput();
            $this->setState($this->getGameScene()->mapState);
            return;
        }
        $changed = false;
        if (Input::isKeyDown(KeyCode::HOME)) {
            $this->selectedId = $this->currentMapId();
            $this->detailPage = 0;
            $changed = true;
        } elseif (Input::isButtonPressed('menu_page_next') || Input::isButtonPressed('menu_page_previous')) {
            $ids = array_keys($this->getKnownGraph()['places']);
            $index = array_search($this->selectedId,$ids,true);
            if ($ids !== []) {
                $offset = Input::isButtonPressed('menu_page_next') ? 1 : -1;
                $this->selectedId = $ids[(($index === false ? 0 : $index)+$offset+count($ids))%count($ids)];
                $this->detailPage = 0;
                $changed = true;
            }
        } else {
            $changed = $this->moveFocus((int)Input::getAxis(AxisName::HORIZONTAL),(int)Input::getAxis(AxisName::VERTICAL));
        }
        if (Input::isButtonPressed('info')) { $this->detailPage++; $changed = true; }
        if ($changed || $this->screenSize !== [get_screen_width(),get_screen_height()]) { $this->refreshUI(); }
    }

    protected function currentMapId(): string { return $this->getGameScene()->currentMapId; }

    protected function getKnownGraph(): array
    {
        return RegionMap::getKnownConnections($this->getGameScene()->gameState,$this->currentMapId());
    }

    /** Directional focus follows schematic positions, independent of keyboard or renderer. */
    protected function moveFocus(int $horizontal,int $vertical): bool
    {
        if ($horizontal===0 && $vertical===0) { return false; }
        $places = $this->getKnownGraph()['places'];
        if ($places===[]) { return false; }
        $positions=[];
        foreach ($places as $id=>$place) { $positions[$id]=$place['position']; }
        $layout=MapConnectionDiagram::getLayout($positions,80,24);
        $focus=DirectionalFocusGraph::createFromPositions($layout['positions'],$this->selectedId ?? $this->currentMapId());
        $direction=$horizontal!==0 ? ($horizontal>0 ? FocusDirection::RIGHT : FocusDirection::LEFT)
            : ($vertical>0 ? FocusDirection::DOWN : FocusDirection::UP);
        if (!$focus->moveFocus($direction)) { return false; }
        $this->selectedId=$focus->getFocusedNode()->id; $this->detailPage=0;
        return true;
    }

    protected function refreshUI(): void
    {
        $size = [get_screen_width(),get_screen_height()];
        if ($this->mapPanel===null || $size!==$this->screenSize) {
            $this->screenSize=$size;
            $width=max(4,min(512,$size[0]));
            $height=max(2,min(256,$size[1]-4));
            $this->mapPanel=new Window($this->getGameScene()->party->location->region,'',new Vector2(0,0),
                $width,$height,heightPolicy:WindowHeightPolicy::FIXED);
            $this->infoPanel=new Window('', '',new Vector2(0,$height),$width,4,heightPolicy:WindowHeightPolicy::FIXED);
        }
        $hints=[MapAtlasTerminal::getFocusHint()];
        foreach (['map_view'=>'map.local','info'=>'map.details','back'=>'map.close'] as $action=>$term) {
            $hint=ActionHints::resolve($action,MessageCatalog::getTerm($term));
            $hints[]=($hint->control?->label ?? 'Unbound').':'.$hint->label;
        }
        Console::recomposeFrame(function () use ($hints): void {
            $this->mapPanel->setHelp(implode('  ',$hints));
            $this->mapPanel->setContent($this->buildMapContent($this->mapPanel->getContentWidth(),$this->mapPanel->getContentHeight()));
            $this->infoPanel->setContent($this->buildInfoContent($this->infoPanel->getContentWidth()));
            $this->mapPanel->render();
            $this->infoPanel->render();
        });
    }

    /** All names belong to the focus panel; diagram geometry contains only blocks and links. */
    protected function buildMapContent(int $width,int $height): array
    {
        if ($width<8 || $height<3) {
            return array_pad([TerminalText::truncateToWidth(MessageCatalog::getTerm('map.small'),max(0,$width))],max(1,$height),'');
        }
        $graph=$this->getKnownGraph();
        if ($graph['places']===[]) { return array_pad([MessageCatalog::getTerm('map.unavailable')],$height,''); }
        if (!isset($graph['places'][$this->selectedId ?? ''])) { $this->selectedId=$this->currentMapId(); }
        $positions=[];
        foreach ($graph['places'] as $id=>$place) { $positions[$id]=$place['position']; }
        $bodyHeight=$height-1;
        $layout=MapConnectionDiagram::getLayout($positions,$width,$bodyHeight);
        $point=$layout['positions'][$this->selectedId] ?? reset($layout['positions']);
        $this->viewX=max(0,min(max(0,$layout['width']-$width),$point[0]-intdiv($width,2)));
        $this->viewY=max(0,min(max(0,$layout['height']-$bodyHeight),$point[1]-intdiv($bodyHeight,2)));
        $links=array_map(static fn(array $link)=>[$link['from'],$link['to']],$graph['links']);
        return [TerminalText::padCenter('N',$width),...MapConnectionDiagram::renderMap($layout['positions'],$links,$width,$bodyHeight,
            $this->currentMapId(),$this->selectedId,$this->viewX,$this->viewY)];
    }

    protected function buildInfoContent(int $width): array
    {
        $graph=$this->getKnownGraph();
        $place=$graph['places'][$this->selectedId ?? ''] ?? null;
        if ($place===null) { return [MessageCatalog::getTerm('map.unavailable'),'']; }
        $name=$place['name'] ?? MessageCatalog::getTerm('map.unknown');
        if ($this->selectedId===$this->currentMapId()) { $name.=' — '.MessageCatalog::getTerm('map.here'); }
        $lines=TerminalText::wrapToWidth($name,max(1,$width));
        $page=$this->detailPage%max(1,(int)ceil(count($lines)/2));
        return array_map(static fn(string $line)=>SelectionStyle::apply($line),
            array_pad(array_slice($lines,$page*2,2),2,''));
    }
}
