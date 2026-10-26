<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Field\MapLandmarkCategory;
use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasRectangle,CanvasTextLayer};
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;

/** Generic bed, bag, home and columned-building pixel pictograms; optional theme artwork replaces them. */
final class MapAtlasPictograms
{
    public static function createLayer(array $markers, CanvasRectangle $bounds, MenuPresentationCatalog $theme,
        bool $opened, string $id = 'atlas-place-pictograms'): ?CanvasTextLayer
    {
        if ($markers===[]) { return null; }
        $unit=max($opened ? 2 : 1,(int)ceil($bounds->width/512),(int)ceil($bounds->height/256));
        $columns=max(7,(int)floor($bounds->width/$unit)); $rows=max(7,(int)floor($bounds->height/$unit));
        if ($columns*$unit>$bounds->width || $rows*$unit>$bounds->height) { return null; }
        $runs=[];
        foreach ($markers as $marker) {
            $pattern=match($marker['category']) {
                MapLandmarkCategory::INN=>['1000000','1000000','1011011','1111111','1111111','1000001','1000001'],
                MapLandmarkCategory::SHOP=>['0011100','0100010','1111111','1000001','1000001','1000001','1111111'],
                MapLandmarkCategory::HOME=>['0001000','0011100','0111110','1100011','0101010','0101010','0111110'],
                MapLandmarkCategory::ORGANIZATION=>['0001000','0111110','1111111','0101010','0101010','0101010','1111111'],
                'route'=>['0001000','0001000','0001000','1001001','0101010','0011100','0001000'],
                'building'=>['1111111','1111111','1111111','1111111','1111111','1111111','1111111'],
                default=>['0001000','0011100','0111110','1111111','0111110','0011100','0001000'],
            };
            $x=max(0,min($columns-7,(int)round(($marker['point']->x-$bounds->x)/$unit)-3));
            $y=max(0,min($rows-7,(int)round(($marker['point']->y-$bounds->y)/$unit)-3));
            for($dy=max(0,$y-1);$dy<min($rows,$y+8);$dy++) {
                $left=max(0,$x-1); $right=min($columns,$x+8);
                $runs[]=new PresentationTextRun($dy,$left,str_repeat(' ',$right-$left),background:$theme->colors['background']);
            }
            // One sparse, bounded layer batches every marker. Blank cells remain transparent.
            foreach ($pattern as $dy=>$line) {
                preg_match_all('/1+/',$line,$matches,PREG_OFFSET_CAPTURE);
                foreach ($matches[0] as [$pixels,$dx]) {
                    $runs[]=new PresentationTextRun($y+$dy,$x+$dx,str_repeat(' ',strlen($pixels)),background:$marker['color']);
                }
            }
        }
        return new CanvasTextLayer($id,44,$bounds->x,$bounds->y,
            new RendererGridConfig($columns,$rows,$unit,$unit),$runs,$bounds);
    }
}
