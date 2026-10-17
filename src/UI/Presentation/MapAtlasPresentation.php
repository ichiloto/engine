<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Closure;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Field\MapLandmarkKind;
use Ichiloto\Engine\Field\MapProjection;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Localization\MessageCatalog;
use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasComposite, CanvasCompositeOperation, CanvasImagePreflight,
    CanvasIndicator, CanvasIndicatorKind, CanvasRectangle, CanvasTextLayer, PresentationCanvas};
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Transport\{RendererGridConfig, RendererSessionConfig};
use Ichiloto\Engine\Util\Debug;
use Throwable;

/** Shared atlas composition. Games replace theme data and artwork, never its renderer. */
final class MapAtlasPresentation
{
    public const array CAPABILITIES = [RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_COMPOSITING,
        RendererSessionConfig::MAP_OVERVIEW, RendererSessionConfig::CANVAS_COMPOSITE_INDICATORS,
        RendererSessionConfig::CANVAS_CLIP_OPACITY, RendererSessionConfig::SPRITE_SOURCE_RECT];
    private ?MenuPresentationCatalog $theme = null;
    private ?string $root = null;
    private array $diagnostics = [];

    public static function supports(Closure $supports): bool { return array_all(self::CAPABILITIES, $supports); }

    public function getCanvas(MapAtlasSnapshot $snapshot, string $root, Closure $supports, int $width, int $height,
        MapAtlasSize $size, int $selected = -1, int $detailPage = 0): ?PresentationCanvas
    {
        if (!self::supports($supports)) { return null; }
        try {
            if ($this->root !== $root) {
                $this->theme = MenuPresentationCatalog::load($root) ?? new MenuPresentationCatalog($root, ['schema'=>'ichiloto.menu/1']);
                $this->root = $root;
            }
            return self::compose($snapshot, $this->theme, $width, $height, $size, $selected, $detailPage);
        } catch (Throwable $error) {
            if (!isset($this->diagnostics[$error->getMessage()])) {
                Debug::warn('Map atlas retains Terminal presentation: ' . $error->getMessage());
                $this->diagnostics[$error->getMessage()] = true;
            }
            return null;
        }
    }

    public static function compose(MapAtlasSnapshot $snapshot, MenuPresentationCatalog $theme, int $width, int $height,
        MapAtlasSize $size, int $selected = -1, int $detailPage = 0): PresentationCanvas
    {
        $full = $size === MapAtlasSize::OPENED;
        $a = $theme->atlas;
        $m = $theme->metrics;
        $margin = min($a->margin, max(0, intdiv(min($width, $height) - 96, 2)));
        $w = $full ? $width - 2*$margin : min($width - 2*$margin, $size === MapAtlasSize::LARGE ? $a->largeWidth : $a->compactWidth);
        $h = $full ? $height - 2*$margin : min($height - 2*$margin, $size === MapAtlasSize::LARGE ? $a->largeHeight : $a->compactHeight);
        $box = new CanvasRectangle($full ? $margin : $width - $margin - $w, $margin, $w, $h);
        $padding = min($m->panelPadding, (int) floor(min($w,$h)/4));
        $contentWidth = $w - 2*$padding;
        $hints = $full ? [ActionHints::resolve('map_view', MessageCatalog::getTerm('map.region')),
            ActionHints::resolve('info', MessageCatalog::getTerm('map.details')), ActionHints::resolve('back', MessageCatalog::getTerm('map.close'))]
            : [ActionHints::resolve('map_size', MessageCatalog::getTerm('map.size')), ActionHints::resolve('map', MessageCatalog::getTerm('map.open'))];
        $hintHeight = $theme->showInputHints ? MenuActionHints::height($hints,$theme,$contentWidth) : 0;
        if (!$full && $h - 2*$padding - $m->cellHeight - $m->sectionGap - $hintHeight < 24) { $hintHeight = 0; }
        if (!$full) {
            $naturalHeight = (int)ceil($contentWidth*$snapshot->overview->height/$snapshot->overview->width);
            $h = min($h,max(96,2*$padding+$m->cellHeight+$m->sectionGap+$hintHeight+$naturalHeight));
            $box = new CanvasRectangle($box->x,$box->y,$w,$h);
        }
        $bodyHeight = max(1, $h - 2*$padding - $m->cellHeight - $m->sectionGap - $hintHeight);
        $legendWidth = $full ? min(400, floor($contentWidth * .31)) : 0;
        $mapBox = new CanvasRectangle($box->x+$padding, $box->y+$padding+$m->cellHeight+$m->sectionGap,
            max(1,$contentWidth-($full ? $legendWidth+$m->sectionGap : 0)), $bodyHeight);
        $projection = MapProjection::fit($snapshot->overview->width, $snapshot->overview->height,
            new Rect((int)$mapBox->x,(int)$mapBox->y,(int)$mapBox->width,(int)$mapBox->height));
        $view = new MenuCanvas($theme,$width,$height,background:$full);
        $view->frame('atlas-frame',$box,$a->frameRole,10);
        $title = TerminalText::truncateToWidth($snapshot->name, max(1,(int)floor($contentWidth/$m->cellWidth)));
        $view->textLines('atlas-title',[['text'=>$title,'color'=>'accent']],
            new CanvasRectangle($box->x+$padding,$box->y+$padding,$contentWidth,$m->cellHeight));
        $destination = new CanvasRectangle($projection->left,$projection->top,$projection->width,$projection->height);
        $rasterScale = min(1, 1024/max($destination->width,$destination->height));
        $rasterWidth = max(1,(int)ceil($destination->width*$rasterScale));
        $rasterHeight = max(1,(int)ceil($destination->height*$rasterScale));
        $raster = new CanvasComposite('atlas-map',$rasterWidth,$rasterHeight,$destination,[new CanvasCompositeOperation([
            'type'=>'world','worldId'=>$snapshot->worldId,'destination'=>['x'=>0,'y'=>0,'width'=>$rasterWidth,'height'=>$rasterHeight],
            'subjects'=>$snapshot->subjects,
        ])],20);
        $indicators = [];
        $bin = max(16,$a->markerSize+4,(int)ceil(sqrt($mapBox->width*$mapBox->height/768)));
        while ((ceil($mapBox->width/$bin)+1)*(ceil($mapBox->height/$bin)+1)>1000) { $bin++; }
        $clusters = [];
        foreach ($snapshot->landmarks as $index=>$landmark) {
            foreach ($landmark['definition']->getMarkerCells() as $cell) {
                $point = $projection->projectCell((int)$cell->x,(int)$cell->y);
                $key = (int)floor(($point->x-$mapBox->x)/$bin) . ':' . (int)floor(($point->y-$mapBox->y)/$bin);
                if (!isset($clusters[$key])) { $clusters[$key]=['point'=>$point,'index'=>$index,'count'=>0,'selected'=>false]; }
                $clusters[$key]['count']++;
                if ($index===$selected) { $clusters[$key]['point']=$point; $clusters[$key]['index']=$index; $clusters[$key]['selected']=true; }
            }
        }
        foreach (array_values($clusters) as $index=>$cluster) {
            $landmark = $snapshot->landmarks[$cluster['index']];
            $kind = $landmark['definition']->kind;
            $point = $cluster['point'];
            $marker = new CanvasRectangle($point->x-$a->markerSize/2,$point->y-$a->markerSize/2,$a->markerSize,$a->markerSize);
            $role = 'map.' . $kind->value;
            if ($cluster['count']===1 && !$cluster['selected'] && $view->icon('atlas-marker-'.$index,$role,$marker,40)) { continue; }
            $color = $cluster['selected'] ? 'focus' : ($landmark['accessible']===false ? 'disabled'
                : ($kind===MapLandmarkKind::BUILDING ? 'increase' : 'accent'));
            $stroke = $cluster['selected'] ? min(3,$a->markerSize) : ($cluster['count']>1 ? 2
                : ($kind->isRoute() ? 2 : (int)ceil($a->markerSize/2)));
            $indicators[] = self::createMarkerHalo('atlas-marker-halo-'.$index,$marker,$theme,39,$width,$height);
            $indicators[] = new CanvasIndicator('atlas-marker-'.$index,'atlas-map',CanvasIndicatorKind::OUTLINE,
                $marker,$stroke,$theme->colors[$color],40);
        }
        $playerX = (int)floor($snapshot->playerX);
        $playerY = (int)floor($snapshot->playerY);
        $text = [];
        if ($playerX>=0 && $playerY>=0 && $playerX<$snapshot->overview->width && $playerY<$snapshot->overview->height) {
            $point = $projection->projectCell($playerX,$playerY);
            $marker = new CanvasRectangle($point->x-$a->markerSize/2,$point->y-$a->markerSize/2,$a->markerSize,$a->markerSize);
            if (!$view->icon('atlas-player','map.player',$marker,50)) {
                $indicators[] = self::createMarkerHalo('atlas-player-halo',$marker,$theme,49,$width,$height);
                $indicators[] = new CanvasIndicator('atlas-player','atlas-map',CanvasIndicatorKind::OUTLINE,$marker,
                    (int)ceil($a->markerSize/2),$theme->colors['focus'],50);
                $heading = match($snapshot->heading) { \Ichiloto\Engine\Core\Enumerations\MovementHeading::NORTH=>'^',
                    \Ichiloto\Engine\Core\Enumerations\MovementHeading::EAST=>'>', \Ichiloto\Engine\Core\Enumerations\MovementHeading::SOUTH=>'v',
                    \Ichiloto\Engine\Core\Enumerations\MovementHeading::WEST=>'<', default=>'@' };
                $text[] = new CanvasTextLayer('atlas-heading',51,$marker->x,$marker->y,
                    new RendererGridConfig(1,1,$a->markerSize,$a->markerSize),[new PresentationTextRun(0,0,$heading,$theme->colors['panel'])]);
            }
        }
        if ($full) {
            $legend = new CanvasRectangle($mapBox->x+$mapBox->width+$m->sectionGap,$mapBox->y,$legendWidth,$bodyHeight);
            self::renderLegend($view,$snapshot,$legend,$selected,$detailPage);
        }
        if ($hintHeight>0) {
            $view->hints('atlas-hints',$hints,new CanvasRectangle($box->x+$padding,$box->y+$h-$padding-$hintHeight,
                $contentWidth,$hintHeight));
        }
        $view->protect($box);
        $base = $view->finish();
        CanvasImagePreflight::inspect($base->images,$theme->assetRoot,[$raster]);
        return new PresentationCanvas($width,$height,$base->images,$indicators,[...$base->textLayers,...$text],[$raster],
            $base->protectedAreas,$base->presentationOwners,$base->labels);
    }

    private static function createMarkerHalo(string $id, CanvasRectangle $marker, MenuPresentationCatalog $theme,
        int $layer,int $width,int $height): CanvasIndicator
    {
        $left = max(0,$marker->x-2);
        $top = max(0,$marker->y-2);
        $bounds = new CanvasRectangle($left,$top,min($width-$left,$marker->width+4),min($height-$top,$marker->height+4));
        return new CanvasIndicator($id,'atlas-map',CanvasIndicatorKind::OUTLINE,$bounds,
            min(4,(int)min($bounds->width,$bounds->height)),$theme->colors['background'],$layer);
    }

    /** One location is named at a time; page actions move one focus target. */
    public static function getLegendPageSize(PresentationCanvas $canvas): int
    {
        foreach ($canvas->textLayers as $layer) {
            if ($layer->id === 'atlas-legend') { return 1; }
        }
        throw new \InvalidArgumentException('Legend paging requires an opened atlas canvas.');
    }

    private static function renderLegend(MenuCanvas $view, MapAtlasSnapshot $snapshot, CanvasRectangle $bounds,
        int $selected,int $detailPage): void
    {
        $m = $view->theme->metrics;
        $columns = max(1,(int)floor($bounds->width/$m->cellWidth));
        $rows = max(1,(int)floor($bounds->height/$m->cellHeight));
        $selected = max(0,min(count($snapshot->landmarks)-1,$selected));
        $lines = $rows>=7 ? [['text'=>MessageCatalog::getTerm('map.legend'),'color'=>'accent'],
            ['text'=>TerminalText::truncateToWidth(MessageCatalog::getTerm('map.graphical_routes'),$columns),'color'=>'accent'],
            ['text'=>TerminalText::truncateToWidth(MessageCatalog::getTerm('map.graphical_places'),$columns),'color'=>'increase'],
            ['text'=>'','color'=>'text']] : [];
        if (isset($snapshot->landmarks[$selected])) {
            $landmark=$snapshot->landmarks[$selected];
            $details = MenuTextWrap::lines(MapAtlasTerminal::getLandmarkLabel($landmark)
                . ($landmark['accessible']===false ? ' ['.MessageCatalog::getTerm('map.locked').']' : ''),$columns);
            $counterRows=$rows>1 ? 1 : 0;
            $pageSize=max(1,$rows-count($lines)-$counterRows);
            $pages = max(1,(int)ceil(count($details)/$pageSize));
            $page = $detailPage % $pages;
            if ($counterRows>0) { $lines[] = ['text'=>($selected+1).'/'.count($snapshot->landmarks)
                . ($pages>1 ? '  '.MessageCatalog::getTerm('map.details').' '.($page+1).'/'.$pages : ''),'color'=>'accent']; }
            foreach (array_slice($details,$page*$pageSize,$pageSize) as $line) { $lines[] = ['text'=>$line,'color'=>'focus']; }
        }
        $view->textLines('atlas-legend',array_slice(array_pad($lines,$rows,['text'=>'','color'=>'text']),0,$rows),$bounds);
    }
}
