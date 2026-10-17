<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\UI\SelectionStyle;

/** One cell per place. Schematic spacing never changes physical map coordinates. */
final class MapConnectionDiagram
{
    private const array LINES = [0=>' ',1=>'│',2=>'─',3=>'└',4=>'│',5=>'│',6=>'┌',7=>'├',
        8=>'─',9=>'┘',10=>'─',11=>'┴',12=>'┐',13=>'┤',14=>'┬',15=>'┼'];

    /** Compress unused station gaps, preserving north/south and east/west ordering.
     * @param array<string, array{0:int,1:int}> $places
     * @return array{positions:array<string,array{0:int,1:int}>,width:int,height:int}
     */
    public static function getLayout(array $places, int $width, int $height): array
    {
        if ($places === []) { return ['positions'=>[], 'width'=>0, 'height'=>0]; }
        $xs = array_values(array_unique(array_column($places,0)));
        $ys = array_values(array_unique(array_column($places,1)));
        sort($xs,SORT_NUMERIC);
        sort($ys,SORT_NUMERIC);
        $stepX = max(2,min(8,intdiv(max(0,$width-3),max(1,count($xs)-1))));
        $stepY = max(2,min(4,intdiv(max(0,$height-3),max(1,count($ys)-1))));
        $columns = array_flip($xs);
        $rows = array_flip($ys);
        $w = (count($xs)-1)*$stepX+3;
        $h = (count($ys)-1)*$stepY+3;
        $left = max(0,intdiv($width-$w,2));
        $top = max(0,intdiv($height-$h,2));
        $positions = [];
        foreach ($places as $id=>[$x,$y]) { $positions[$id]=[1+$left+$columns[$x]*$stepX,1+$top+$rows[$y]*$stepY]; }
        return ['positions'=>$positions,'width'=>$w,'height'=>$h];
    }

    /** Render only viewport-sized storage, even when stations are very distant.
     * @param array<string,array{0:int,1:int}> $positions
     * @param list<array{0:string,1:string}> $links
     * @return list<string>
     */
    public static function renderMap(array $positions,array $links,int $width,int $height,
        string $current,string $selected,int $viewX=0,int $viewY=0): array
    {
        if ($width<1 || $height<1 || $width>512 || $height>256) {
            throw new \InvalidArgumentException('A connection diagram requires 1–512 columns and 1–256 rows.');
        }
        $bits = $bright = $arrows = $drawn = [];
        $directions=[];
        foreach ($links as [$from,$to]) { $directions[$from][$to]=true; }
        foreach ($links as [$from,$to]) {
            if (!isset($positions[$from],$positions[$to])) { continue; }
            $pair=[$from,$to]; sort($pair,SORT_STRING);
            $key=json_encode($pair,JSON_THROW_ON_ERROR);
            if (isset($drawn[$key]) || $from===$to) { continue; }
            $drawn[$key]=true;
            $path = self::getRoute($positions[$from],$positions[$to],$positions);
            $focused = $from===$selected || $to===$selected;
            for ($index=1;$index<count($path);$index++) {
                self::paintSegment($bits,$bright,$path[$index-1],$path[$index],$width,$height,$viewX,$viewY,$focused);
            }
            if (!isset($directions[$to][$from])) {
                $end=$positions[$to];
                for ($index=count($path)-2;$index>=0;$index--) {
                    $prior=$path[$index];
                    if ($prior===$end) { continue; }
                    $dx=$end[0]<=>$prior[0]; $dy=$end[1]<=>$prior[1];
                    $x=$end[0]-$dx-$viewX; $y=$end[1]-$dy-$viewY;
                    if ($x>=0 && $y>=0 && $x<$width && $y<$height) {
                        $arrows[$y][$x]=[$dx>0?'→':($dx<0?'←':($dy>0?'↓':'↑')),$focused];
                    }
                    break;
                }
            }
        }
        $canvas = array_fill(0,$height,array_fill(0,$width,' '));
        foreach ($bits as $y=>$row) {
            foreach ($row as $x=>$mask) {
                $glyph = self::LINES[$mask];
                $canvas[$y][$x] = isset($bright[$y][$x]) ? SelectionStyle::apply($glyph) : Color::apply($glyph,Color::DARK_GRAY);
            }
        }
        foreach ($arrows as $y=>$row) {
            foreach ($row as $x=>[$glyph,$focused]) {
                $canvas[$y][$x]=$focused ? SelectionStyle::apply($glyph) : Color::apply($glyph,Color::DARK_GRAY);
            }
        }
        foreach ($positions as $id=>[$x,$y]) {
            $x-=$viewX; $y-=$viewY;
            if ($x<0 || $y<0 || $x>=$width || $y>=$height) { continue; }
            $canvas[$y][$x] = $id===$selected ? SelectionStyle::apply('■')
                : ($id===$current ? Color::apply('■',Color::LIGHT_CYAN) : '■');
        }
        return array_map(static fn(array $row)=>implode('',$row),$canvas);
    }

    /** Select the shortest clear orthogonal route; never draw through an unrelated place. */
    private static function getRoute(array $from,array $to,array $positions): array
    {
        $candidates = [[$from,[$to[0],$from[1]],$to],[$from,[$from[0],$to[1]],$to]];
        foreach ([-1,1] as $offset) {
            $candidates[] = [$from,[$from[0],$from[1]+$offset],[$to[0],$from[1]+$offset],$to];
            $candidates[] = [$from,[$from[0]+$offset,$from[1]],[$from[0]+$offset,$to[1]],$to];
        }
        // Empty lanes alongside node rows and an outside column always give a
        // route, including a direct link that skips over another place.
        $outside = min(array_column($positions,0))-1;
        $candidates[] = [$from,[$from[0],$from[1]-1],[$outside,$from[1]-1],
            [$outside,$to[1]-1],[$to[0],$to[1]-1],$to];
        $best = null; $bestLength = PHP_INT_MAX;
        foreach ($candidates as $candidate) {
            $length=0; $clear=true;
            for ($index=1;$index<count($candidate);$index++) {
                [$a,$b]=[$candidate[$index-1],$candidate[$index]];
                $length+=abs($a[0]-$b[0])+abs($a[1]-$b[1]);
                foreach ($positions as $point) {
                    if ($point===$from || $point===$to) { continue; }
                    if (($a[0]===$b[0] && $point[0]===$a[0] && $point[1]>=min($a[1],$b[1]) && $point[1]<=max($a[1],$b[1]))
                        || ($a[1]===$b[1] && $point[1]===$a[1] && $point[0]>=min($a[0],$b[0]) && $point[0]<=max($a[0],$b[0]))) {
                        $clear=false; break;
                    }
                }
                if (!$clear) { break; }
            }
            if ($clear && $length<$bestLength) { $best=$candidate; $bestLength=$length; }
        }
        return $best ?? throw new \LogicException('Schematic location lanes must remain routable.');
    }

    private static function paintSegment(array &$bits,array &$bright,array $from,array $to,
        int $width,int $height,int $viewX,int $viewY,bool $focused): void
    {
        [$ax,$ay]=[$from[0]-$viewX,$from[1]-$viewY];
        [$bx,$by]=[$to[0]-$viewX,$to[1]-$viewY];
        if ($ax===$bx && $ay===$by) { return; }
        if ($ay===$by) {
            if ($ay<0 || $ay>=$height) { return; }
            for ($x=max(0,min($ax,$bx));$x<=min($width-1,max($ax,$bx));$x++) {
                $mask=($x>min($ax,$bx)?8:0)|($x<max($ax,$bx)?2:0);
                $bits[$ay][$x]=($bits[$ay][$x]??0)|$mask;
                if ($focused) { $bright[$ay][$x]=true; }
            }
        } else {
            if ($ax<0 || $ax>=$width) { return; }
            for ($y=max(0,min($ay,$by));$y<=min($height-1,max($ay,$by));$y++) {
                $mask=($y>min($ay,$by)?1:0)|($y<max($ay,$by)?4:0);
                $bits[$y][$ax]=($bits[$y][$ax]??0)|$mask;
                if ($focused) { $bright[$y][$ax]=true; }
            }
        }
    }
}
