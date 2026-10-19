<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Core\{Rect,Vector2};
use Ichiloto\Engine\Field\{MapAtlasDocument,MapAtlasView};
use Ichiloto\Engine\IO\{ActionHints,Input,InputManager};
use Ichiloto\Engine\IO\Console\{Console,TerminalText};
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\Localization\MessageCatalog;
use Ichiloto\Engine\Rendering\Presentation\{PresentationWorld,Canvas\PresentationCanvas,Canvas\CanvasProviderInterface};
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Presentation\{MapAtlasTerminal,MapAtlasSnapshot,MapAtlasSize};
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\UI\Windows\{Window,Enumerations\WindowHeightPolicy};
use Ichiloto\Engine\Util\Debug;

/** Scrollable map camera with focused place details and a separate real-world view. */
class LocalMapState extends GameSceneState implements CanvasProviderInterface
{
    protected ?Window $window = null;
    protected int $selected = -1;
    protected int $detailPage = 0;
    protected int $legendPageSize = 1;
    protected ?MapAtlasView $view = null;
    private array $screenSize = [];
    private bool $showGlobal = false;
    private ?array $globalBinding = null;
    private ?MapAtlasDocument $globalDocument = null;
    private array $globalOptions = [];
    private int $globalIndex = 0;
    private ?string $presentationDiagnostic = null;
    /** @var array{map: string, waymarks: list<\Ichiloto\Engine\Field\Waymarks\FieldWaymark>}|null */
    private ?array $globalWaymarks = null;

    public function getPresentationCanvas(): ?PresentationCanvas
    {
        try { $snapshot=$this->getSnapshot(true); }
        catch (\Throwable $error) { $this->reportPresentationFailure($error); return null; }
        if ($snapshot===null) { return null; }
        $this->view ??= $this->createView($snapshot);
        return $this->getGameScene()->getMapAtlasCanvas(MapAtlasSize::OPENED,selected:$this->selected,
            detailPage:$this->detailPage,view:$this->view,snapshot:$snapshot);
    }

    public function getAtlasWorld(): ?PresentationWorld
    {
        $scene=$this->getGameScene();
        try {
            return $this->showGlobal && $this->globalDocument!==null && $scene->gameState!==null
                ? $this->globalDocument->getPresentationWorld($scene->gameState,$scene->party)
                : $scene->mapManager?->getPresentationWorld();
        } catch (\Throwable $error) { $this->reportPresentationFailure($error); return null; }
    }

    public function enter(): void
    {
        InputManager::consumeCurrentInput();
        $this->selected=-1; $this->detailPage=0;
        $scene=$this->getGameScene();
        $this->globalBinding=null; $this->globalDocument=null; $this->view=null; $this->globalWaymarks=null;
        $this->showGlobal=$scene->mapManager?->atlasDefinition?->global ?? false;
        try { $this->globalBinding=$scene->mapManager?->resolveGlobalAtlas($scene->currentMapId); }
        catch (\Throwable $error) { Debug::warn('Map world reference is unavailable: '.$error->getMessage()); }
        $this->globalOptions=$scene->mapManager?->readGlobalAtlasMaps() ?? [];
        if ($this->globalBinding!==null) {
            $this->globalOptions=array_values(array_filter($this->globalOptions,fn(array $item)=>$item['map']!==$this->globalBinding['map']));
            array_unshift($this->globalOptions,$this->globalBinding);
        }
        $this->globalIndex=0;
        $this->globalBinding=$this->globalOptions[0] ?? null;
        $scene->locationHUDWindow?->deactivate();
        Console::clear();
        $this->refreshUI();
    }

    public function exit(): void
    {
        $this->globalDocument=null; $this->view=null;
        Console::clear();
        $this->getGameScene()->locationHUDWindow?->activate();
    }

    public function execute(?SceneStateContext $context = null): void
    {
        $scene=$this->getGameScene();
        if (Input::isButtonPressed('back') || Input::isButtonPressed('cancel') || Input::isButtonPressed('map')) {
            InputManager::consumeCurrentInput(); $this->setState($scene->fieldState); return;
        }
        $snapshot=$this->getSnapshot(false);
        if ($snapshot===null) { return; }
        $this->view ??= $this->createView($snapshot);
        $changed=false;
        if (Input::isButtonPressed('map_view') && $snapshot->canSwitch) {
            InputManager::consumeCurrentInput();
            try {
                if ($this->showGlobal && $this->globalIndex+1<count($this->globalOptions)) {
                    $this->globalIndex++; $this->globalBinding=$this->globalOptions[$this->globalIndex];
                    $this->globalDocument=$scene->mapManager->createAtlasDocument($this->globalBinding['map']);
                } elseif ($this->showGlobal) {
                    $this->showGlobal=false; $this->globalIndex=0; $this->globalBinding=$this->globalOptions[0];
                    $this->globalDocument=null;
                } else {
                    $this->globalDocument=$scene->mapManager->createAtlasDocument($this->globalBinding['map']);
                    $this->showGlobal=true;
                }
                $this->selected=-1; $this->detailPage=0; $this->view=null;
                $changed=true;
            } catch (\Throwable $error) { Debug::warn('World atlas unavailable: '.$error->getMessage()); }
        } else {
            if (Input::isButtonPressed('map_zoom') && $scene->canPresentMapAtlas()) {
                $this->view->toggleZoom(); $changed=true;
            }
            if (Input::isButtonPressed('map_recenter')) {
                $point=$this->createView($snapshot);
                $this->view->centreOn($point->centreX,$point->centreY); $changed=true;
            }
            if (Input::isButtonPressed('menu_page_next') || Input::isButtonPressed('menu_page_previous')) {
                $count=count($snapshot->landmarks);
                if ($count>0) {
                    $offset=Input::isButtonPressed('menu_page_next') ? 1 : -1;
                    $this->selected=$this->selected<0 ? ($offset>0 ? 0 : $count-1) : ($this->selected+$offset+$count)%$count;
                    $this->detailPage=0;
                    $point=$snapshot->landmarks[$this->selected]['definition']->getMarkerCells()[0];
                    $this->view->centreOn($point->x+0.5,$point->y+0.5); $changed=true;
                }
            } else {
                $moved=$this->view->moveView((int)Input::getAxis(AxisName::HORIZONTAL),
                    (int)Input::getAxis(AxisName::VERTICAL));
                if ($moved) { $this->selected=-1; $this->detailPage=0; }
                $changed=$moved || $changed;
            }
        }
        if (Input::isButtonPressed('info')) { $this->detailPage++; $changed=true; }
        if ($changed || $this->screenSize!==[get_screen_width(),get_screen_height()]) { $this->refreshUI(); }
    }

    protected function getSnapshot(bool $graphical): ?MapAtlasSnapshot
    {
        $scene=$this->getGameScene();
        if ($scene->player===null || $scene->mapManager?->overview===null) { return null; }
        $canSwitch=$this->globalBinding!==null && !($scene->mapManager->atlasDefinition?->global ?? false);
        if ($this->showGlobal && $this->globalDocument!==null && $scene->gameState!==null) {
            $snapshot=$this->globalDocument->getSnapshot($scene->gameState,$scene->party,$this->globalBinding['landmark'],
                $scene->player->heading,$graphical);
        } elseif ($graphical) {
            $snapshot=$scene->getMapAtlasSnapshot();
            if ($snapshot===null) { return null; }
        } else {
            $snapshot=new MapAtlasSnapshot($scene->party->location->name,'map',$scene->mapManager->overview,[],
                $this->getVisibleLandmarks(),$scene->player->position->x,$scene->player->position->y,$scene->player->heading,
                $scene->mapManager->atlasDefinition?->global ?? false,
                terminalView:$scene->mapManager->atlasDefinition?->terminalView ?? 'floorplan');
        }
        return $snapshot->with($canSwitch,$this->showGlobal && $this->globalDocument!==null
            ? $this->getGlobalWaymarks() : $scene->getFieldWaymarks());
    }

    /**
     * Objectives on the open world map. Neither quests nor the world change
     * while the map is open, so each world map resolves them once.
     *
     * @return list<\Ichiloto\Engine\Field\Waymarks\FieldWaymark>
     */
    private function getGlobalWaymarks(): array
    {
        $scene=$this->getGameScene();
        $document=$this->globalDocument;
        if ($document===null || $scene->questManager===null || $scene->mapManager===null) { return []; }
        if ($this->globalWaymarks===null || $this->globalWaymarks['map']!==$document->id) {
            $this->globalWaymarks=['map'=>$document->id,'waymarks'=>\Ichiloto\Engine\Field\Waymarks\MapAtlasWaymarks::find(
                $document,$scene->questManager->getActiveWaymarks(),$scene->mapManager->resolveGlobalAtlas(...),
                $scene->gameState,$scene->party)];
        }
        return $this->globalWaymarks['waymarks'];
    }

    protected function getVisibleLandmarks(): array
    {
        $scene=$this->getGameScene();
        return $scene->gameState===null ? [] : ($scene->mapManager?->mapKnowledge?->getVisibleLandmarks($scene->gameState,$scene->party) ?? []);
    }

    protected function refreshUI(): void
    {
        $size=[get_screen_width(),get_screen_height()];
        $snapshot=$this->getSnapshot(false);
        if ($this->window===null || $size!==$this->screenSize) {
            $this->screenSize=$size;
            $this->window=new Window('','',new Vector2(0,0),max(4,$size[0]),max(4,$size[1]),heightPolicy:WindowHeightPolicy::FIXED);
        }
        $this->window->setTitle(($snapshot?->global ? MessageCatalog::getTerm('map.region') : MessageCatalog::getTerm('map.local'))
            .' — '.($snapshot?->name ?? ''));
        $actions=$snapshot?->canSwitch ? ['map_view'=>$snapshot->global ? 'map.local' : 'map.region'] : [];
        if ($this->getGameScene()->canPresentMapAtlas()) { $actions['map_zoom']='map.zoom'; }
        $actions+=['map_recenter'=>'map.recenter','back'=>'map.close'];
        $hints=[];
        foreach ($actions as $action=>$term) {
            $hint=ActionHints::resolve($action,MessageCatalog::getTerm($term));
            $hints[]=($hint->control?->label ?? 'Unbound').':'.$hint->label;
        }
        $hints[]=str_replace(MessageCatalog::getTerm('map.focus'),MessageCatalog::getTerm('map.scroll'),MapAtlasTerminal::getFocusHint());
        Console::recomposeFrame(function() use($hints): void {
            $this->window->setHelp(implode('  ',$hints));
            $this->window->setContent($this->buildContent($this->window->getContentWidth(),$this->window->getContentHeight()));
            $this->window->render();
        });
    }

    protected function buildContent(int $width, int $height): array
    {
        if ($width<12 || $height<6) { return [TerminalText::truncateToWidth(MessageCatalog::getTerm('map.small'),max(0,$width))]; }
        $snapshot=$this->getSnapshot(false);
        if ($snapshot===null) { return [MessageCatalog::getTerm('map.unavailable')]; }
        $this->view ??= $this->createView($snapshot);
        $landmarks=$snapshot->landmarks;
        $this->selected=max(-1,min(count($landmarks)-1,$this->selected));
        $mapHeight=min(256,$height-5); $mapWidth=min(512,$width);
        $projection=$this->view->getProjection($snapshot->overview,new Rect(0,0,$mapWidth,$mapHeight),false,$snapshot->global);
        $style=$this->getGameScene()->fieldEffects?->waymarkStyle;
        $map=MapAtlasTerminal::renderMap($snapshot->overview,$landmarks,new Vector2($snapshot->playerX,$snapshot->playerY),
            $snapshot->heading,$mapWidth,$mapHeight,$this->selected,$projection,$snapshot->global,$snapshot->terminalView==='floorplan',
            $snapshot->waymarks,$style);
        $rows=[TerminalText::padCenter('N',$width),...$map];
        $legend=$snapshot->overview->terrain!==null
            ? MapAtlasTerminal::getTerrainLegend($snapshot->overview,$snapshot->global,$snapshot->terminalView==='places')
            : MessageCatalog::getTerm($snapshot->global ? 'map.global_symbols' : ($snapshot->terminalView==='places' ? 'map.place_symbols' : 'map.symbols'));
        if ($snapshot->waymarks!==[]) {
            $legend=($style?->symbol ?? '!').' '.MessageCatalog::getTerm('map.objective').'   '.$legend;
        }
        $rows[]=TerminalText::truncateToWidth($legend,$width);
        $focused=$landmarks[$this->selected] ?? null;
        $detail=$focused===null ? ($snapshot->playerX<0 ? MessageCatalog::getTerm('map.position_unmarked') : '') : MapAtlasTerminal::getLandmarkLabel($focused)
            .($focused['accessible']===false ? ' ['.MessageCatalog::getTerm('map.locked').']' : '');
        $lines=$detail==='' ? [] : TerminalText::wrapToWidth($detail,$width);
        $pages=max(1,(int)ceil(count($lines)/2)); $page=$this->detailPage%$pages;
        $rows[]=($this->selected<0 ? '' : ($this->selected+1).'/'.count($landmarks))
            .($pages>1 ? '  '.MessageCatalog::getTerm('map.details').' '.($page+1).'/'.$pages : '');
        array_push($rows,...array_map(static fn(string $line)=>SelectionStyle::apply($line),array_pad(array_slice($lines,$page*2,2),2,'')));
        return array_slice($rows,0,$height);
    }

    private function createView(MapAtlasSnapshot $snapshot): MapAtlasView
    {
        return new MapAtlasView($snapshot->playerX<0 ? $snapshot->overview->width/2 : $snapshot->playerX+0.5,
            $snapshot->playerY<0 ? $snapshot->overview->height/2 : $snapshot->playerY+0.5);
    }

    private function reportPresentationFailure(\Throwable $error): void
    {
        if ($this->presentationDiagnostic!==$error->getMessage()) {
            $this->presentationDiagnostic=$error->getMessage();
            Debug::warn('Map graphics are unavailable; retaining its Terminal map: '.$error->getMessage());
        }
    }
}
