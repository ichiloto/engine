<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Rendering\FieldViewport;

/** Player-controlled map camera. It never moves the gameplay camera or discovers locations. */
final class MapAtlasView
{
    public private(set) bool $zoomedIn = false;
    public private(set) float $centreX;
    public private(set) float $centreY;
    private ?MapProjection $projection = null;

    public function __construct(float $x, float $y) { $this->centreOn($x,$y); }

    public function centreOn(float $x, float $y): void
    {
        if (!is_finite($x) || !is_finite($y)) { throw new \InvalidArgumentException('Map centre must be finite.'); }
        $this->centreX=$x; $this->centreY=$y;
    }

    public function toggleZoom(): void { $this->zoomedIn=!$this->zoomedIn; }

    public function moveView(int $horizontal, int $vertical): bool
    {
        if ($this->projection===null || ($horizontal===0 && $vertical===0)) { return false; }
        $p=$this->projection;
        $w=$p->sourceWidth ?? $p->mapWidth; $h=$p->sourceHeight ?? $p->mapHeight;
        if (($horizontal===0 || $w>=$p->mapWidth) && ($vertical===0 || $h>=$p->mapHeight)) { return false; }
        $x=max($w/2,min($p->mapWidth-$w/2,$p->sourceX+$w/2+$horizontal*max(1,$w/12)));
        $y=max($h/2,min($p->mapHeight-$h/2,$p->sourceY+$h/2+$vertical*max(1,$h/12)));
        if ($x===$this->centreX && $y===$this->centreY) { return false; }
        $this->centreOn($x,$y);
        return true;
    }

    public function getProjection(MapOverview $overview, Rect $bounds, bool $graphical, bool $global = false): MapProjection
    {
        if ($global) {
            $fit=MapProjection::fit($overview->width,$overview->height,$bounds,1,$graphical ? 1 : 2);
            if (!$graphical || !$this->zoomedIn) { return $this->projection=$fit; }
            return $this->projection=MapProjection::createViewport($overview->width,$overview->height,$bounds,
                $this->centreX,$this->centreY,$fit->scaleX*2,$fit->scaleY*2);
        }
        // Both graphical levels reveal more ground than normal one-tile field rendering.
        $scale=$graphical ? FieldViewport::TILE_SIZE/($this->zoomedIn ? 2 : 4) : 1;
        return $this->projection=MapProjection::createViewport($overview->width,$overview->height,$bounds,
            $this->centreX,$this->centreY,$scale,$scale);
    }
}
