<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasWorldSubject,CanvasImage,CanvasRectangle};
use Ichiloto\Engine\Rendering\Sprites\FieldSpriteRole;
use Ichiloto\Engine\UI\Presentation\MapAtlasSnapshot;

/** Read-only map source. Opening it installs no events, characters, music, camera or discoveries. */
final class MapAtlasDocument
{
    private ?PresentationWorld $world = null;
    private array $subjects = [];
    private ?string $worldState = null;

    public function __construct(public readonly string $id, public readonly array $source,
        public readonly MapOverview $overview, public readonly MapLandmarkCatalog $landmarks,
        private readonly string $assetRoot) {}

    public function getSnapshot(GameState $state, ?Party $party, ?string $entry, MovementHeading $heading,
        bool $graphical = true): MapAtlasSnapshot
    {
        $x=$y=-1;
        if ($entry!==null) {
            foreach ($this->landmarks->landmarks as $landmark) {
                if ($landmark->id!==$entry) { continue; }
                $point=$landmark->getMarkerCells()[0]; $x=$point->x; $y=$point->y;
                break;
            }
        }
        return new MapAtlasSnapshot($this->source['data']['name'] ?? '',$graphical ? $this->getPresentationWorld($state,$party)->id : 'atlas-world',
            $this->overview,$graphical ? $this->subjects : [],(new MapKnowledge($this->id,$this->landmarks))->getVisibleLandmarks($state,$party),
            $x,$y,$heading,true,false,MapAtlasDefinition::readMap($this->source['data'])->terminalView);
    }

    public function getPresentationWorld(GameState $state, ?Party $party): PresentationWorld
    {
        $variants=[];
        foreach ($this->source['worldObjects'] as $object) { $variants[]=[$object->isAvailable($state,$party),$object->selectVariant($state,$party)['id']]; }
        $key=serialize($variants);
        if ($this->world!==null && $key===$this->worldState) { return $this->world; }
        $coverage=[]; $subjects=[]; $tiles=[];
        foreach ($this->source['worldObjects'] as $object) {
            if (!$object->isAvailable($state,$party)) { continue; }
            foreach ($object->tilePresentation as $name=>$rows) {
                foreach ($rows as $y=>$cells) { $tiles[$name][$y]=($tiles[$name][$y] ?? [])+$cells; }
            }
            $subject=$this->createSubject($object,$state,$party);
            if ($subject!==null) { $subjects[]=$subject; }
            foreach ($object->coverage as $kind=>$names) {
                if ($kind==='glyphs' && $subject===null) { continue; }
                foreach ($names as $name=>$rows) {
                    foreach ($rows as $y=>$cells) { $coverage[$kind][$name][$y]=($coverage[$kind][$name][$y] ?? [])+$cells; }
                }
            }
        }
        $world=PresentationWorld::getFromLayers($this->source['layers'],'atlas-world',$this->source['graphics'],$this->assetRoot,$coverage,tileCells: $tiles);
        $this->world=$world; $this->subjects=$subjects; $this->worldState=$key;
        return $world;
    }

    private function createSubject(WorldObjectDefinition $object, GameState $state, ?Party $party): ?CanvasWorldSubject
    {
        if (!$object->isAvailable($state,$party)) { return null; }
        // Global atlases carry map-owned geometry and rest poses; actors and sign captions stay out.
        if (($style=$object->resource?->sign)!==null) {
            $board=$style->getBoard($this->assetRoot);
            $width=$style->minimumWidth; $height=(int)ceil($board->source->height/$style->density);
            $images=$board->images('board',new CanvasRectangle(0,0,$width,$height),0);
            return new CanvasWorldSubject('overview:'.$object->id,$object->x,$object->y,
                $width/FieldViewport::TILE_SIZE,$height/FieldViewport::TILE_SIZE,$width,$height,$object->pivot,$images,$style->layer);
        }
        $sprites=$object->selectVariant($state,$party)['sprites'];
        if ($sprites===null) { return null; }
        $role=new FieldSpriteRole($sprites,$this->assetRoot,'Atlas object '.$object->id);
        try { $frame=$role->getStillFrame($object->pivot); }
        finally { $role->release(); }
        if ($frame===null) { return null; }
        return new CanvasWorldSubject('overview:'.$object->id,$object->x,$object->y,
            $frame->width/FieldViewport::TILE_SIZE,$frame->height/FieldViewport::TILE_SIZE,$frame->width,$frame->height,
            $object->pivot,[new CanvasImage('still',$frame->asset,new CanvasRectangle(0,0,$frame->width,$frame->height),
                sourceRect:$frame->sourceRect)],$frame->layer,$frame->lift/FieldViewport::TILE_SIZE,$frame->quarterTurns);
    }
}
