<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Closure;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Field\MapLandmarkKind;
use Ichiloto\Engine\Field\MapCategoryCatalog;
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
    public const string PLAYER_ICON_ROLE = 'map.player';
    public const array CAPABILITIES = [RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_COMPOSITING,
        RendererSessionConfig::MAP_OVERVIEW, RendererSessionConfig::MAP_OVERVIEW_VIEWPORT, RendererSessionConfig::CANVAS_COMPOSITE_INDICATORS,
        RendererSessionConfig::CANVAS_CLIP_OPACITY, RendererSessionConfig::SPRITE_SOURCE_RECT];
    private ?MenuPresentationCatalog $theme = null;
    private ?string $root = null;
    private array $diagnostics = [];

    /** Every optional icon role consumed here, shared with theme authoring. */
    public static function listIconRoles(MapCategoryCatalog $categories): array
    {
        return [self::PLAYER_ICON_ROLE,
            ...array_map(static fn(MapLandmarkKind $kind): string=>$kind->getIconRole(),MapLandmarkKind::cases()),
            ...array_map(MapCategoryCatalog::getIconRole(...),$categories->getIds())];
    }

    public static function supports(Closure $supports): bool { return array_all(self::CAPABILITIES, $supports); }

    public function getCanvas(MapAtlasSnapshot $snapshot, string $root, Closure $supports, int $width, int $height,
        MapAtlasSize $size, int $selected = -1, int $detailPage = 0,
        ?\Ichiloto\Engine\Field\MapAtlasView $mapView = null): ?PresentationCanvas
    {
        if ($size===MapAtlasSize::OFF || !self::supports($supports)) { return null; }
        try {
            if ($this->root !== $root) {
                $this->theme = MenuPresentationCatalog::load($root) ?? new MenuPresentationCatalog($root, ['schema'=>'ichiloto.menu/1']);
                $this->root = $root;
            }
            return self::compose($snapshot, $this->theme, $width, $height, $size, $selected, $detailPage,$mapView);
        } catch (Throwable $error) {
            if (!isset($this->diagnostics[$error->getMessage()])) {
                Debug::warn('Map atlas retains Terminal presentation: ' . $error->getMessage());
                $this->diagnostics[$error->getMessage()] = true;
            }
            return null;
        }
    }

    public static function compose(MapAtlasSnapshot $snapshot, MenuPresentationCatalog $theme, int $width, int $height,
        MapAtlasSize $size, int $selected = -1, int $detailPage = 0,
        ?\Ichiloto\Engine\Field\MapAtlasView $mapView = null): PresentationCanvas
    {
        if ($size===MapAtlasSize::OFF) { throw new \InvalidArgumentException('Hidden corner maps have no canvas.'); }
        $full = $size === MapAtlasSize::OPENED;
        $a = $theme->atlas;
        $m = $theme->metrics;
        $margin = min($a->margin, max(0, intdiv(min($width, $height) - 96, 2)));
        $w = $full ? $width - 2*$margin : min($width - 2*$margin, $size === MapAtlasSize::LARGE ? $a->largeWidth : $a->compactWidth);
        $h = $full ? $height - 2*$margin : min($height - 2*$margin, $size === MapAtlasSize::LARGE ? $a->largeHeight : $a->compactHeight);
        // A corner atlas is map content with a one-pixel rim. Menu typography and
        // padding apply only to the opened map; whole-map aspect owns the corner silhouette.
        $padding = $full ? min($m->panelPadding, (int) floor(min($w,$h)/4)) : 1;
        if (!$full) {
            $scale = min(($w-2*$padding)/$snapshot->overview->width,($h-2*$padding)/$snapshot->overview->height);
            $w = (int)ceil($snapshot->overview->width*$scale)+2*$padding;
            $h = (int)ceil($snapshot->overview->height*$scale)+2*$padding;
        }
        $box = new CanvasRectangle($full ? $margin : $width - $margin - $w, $margin, $w, $h);
        $contentWidth = $w - 2*$padding;
        $hints = $full ? [...($snapshot->canSwitch ? [ActionHints::resolve('map_view',
                MessageCatalog::getTerm($snapshot->global ? 'map.local' : 'map.region'))] : []),
            ...($mapView!==null ? [ActionHints::resolve('map_zoom',MessageCatalog::getTerm('map.zoom')),
                ActionHints::resolve('map_recenter',MessageCatalog::getTerm('map.recenter'))] : []),
            ActionHints::resolve('right',MessageCatalog::getTerm('map.scroll')),
            ActionHints::resolve('menu_page_next',MessageCatalog::getTerm('map.focus')),
            ActionHints::resolve('info', MessageCatalog::getTerm('map.details')), ActionHints::resolve('back', MessageCatalog::getTerm('map.close'))]
            : [];
        // The opened map always exposes its primary controls. Corner maps carry no text chrome.
        $hintHeight = $full ? MenuActionHints::height($hints,$theme,$contentWidth,required:true) : 0;
        $titleHeight = $full ? $m->cellHeight+$m->sectionGap : 0;
        $bodyHeight = max(1, $h - 2*$padding - $titleHeight - $hintHeight);
        $legendWidth = $full ? min(400, floor($contentWidth * .31)) : 0;
        $mapBox = new CanvasRectangle($box->x+$padding, $box->y+$padding+$titleHeight,
            max(1,$contentWidth-($full ? $legendWidth+$m->sectionGap : 0)), $bodyHeight);
        $bounds=new Rect((int)$mapBox->x,(int)$mapBox->y,(int)$mapBox->width,(int)$mapBox->height);
        $projection = $full && $mapView!==null ? $mapView->getProjection($snapshot->overview,$bounds,true,$snapshot->global)
            : MapProjection::fit($snapshot->overview->width,$snapshot->overview->height,$bounds);
        $view = new MenuCanvas($theme,$width,$height,background:$full);
        if ($full) { $view->frame('atlas-frame',$box,$a->frameRole,10); }
        if ($full) {
            $title = TerminalText::truncateToWidth(MessageCatalog::getTerm($snapshot->global ? 'map.region' : 'map.local').' — '
                .$snapshot->name.($mapView!==null ? ' — '.MessageCatalog::getTerm($mapView->zoomedIn ? 'map.zoom_in' : 'map.zoom_out') : ''),
                max(1,(int)floor($contentWidth/$m->cellWidth)));
            $view->textLines('atlas-title',[['text'=>$title,'color'=>'accent']],
                new CanvasRectangle($box->x+$padding,$box->y+$padding,$contentWidth,$m->cellHeight));
        } else {
            $view->renderOutline('atlas-rim',$box,'edge',1,21,$a->cornerOpacity);
        }
        $destination = new CanvasRectangle($projection->left,$projection->top,$projection->width,$projection->height);
        $rasterScale = min(1, 1024/max($destination->width,$destination->height));
        $rasterWidth = max(1,(int)ceil($destination->width*$rasterScale));
        $rasterHeight = max(1,(int)ceil($destination->height*$rasterScale));
        $raster = new CanvasComposite('atlas-map',$rasterWidth,$rasterHeight,$destination,[new CanvasCompositeOperation([
            'type'=>'world','worldId'=>$snapshot->worldId,'destination'=>['x'=>0,'y'=>0,'width'=>$rasterWidth,'height'=>$rasterHeight],
            'subjects'=>$snapshot->subjects,
            ...($full && $mapView!==null ? ['source'=>['x'=>$projection->sourceX,'y'=>$projection->sourceY,
                'width'=>$projection->sourceWidth ?? $snapshot->overview->width,
                'height'=>$projection->sourceHeight ?? $snapshot->overview->height]] : []),
        ])],20,opacity:$full ? 1 : $a->cornerOpacity);
        $indicators = [];
        $bin = max(16,$a->markerSize+4,(int)ceil(sqrt($mapBox->width*$mapBox->height/768)));
        while ((ceil($mapBox->width/$bin)+1)*(ceil($mapBox->height/$bin)+1)>1000) { $bin++; }
        $clusters = [];
        foreach ($snapshot->landmarks as $index=>$landmark) {
            foreach ($landmark['definition']->getMarkerCells() as $cell) {
                if (!$projection->containsCell((int)$cell->x,(int)$cell->y)) { continue; }
                $point = $projection->projectCell((int)$cell->x,(int)$cell->y);
                $key = (int)floor(($point->x-$mapBox->x)/$bin) . ':' . (int)floor(($point->y-$mapBox->y)/$bin);
                if (!isset($clusters[$key])) { $clusters[$key]=['point'=>$point,'index'=>$index,'count'=>0,'selected'=>false]; }
                $clusters[$key]['count']++;
                if ($index===$selected) { $clusters[$key]['point']=$point; $clusters[$key]['index']=$index; $clusters[$key]['selected']=true; }
            }
        }
        $pictograms=[];
        foreach (array_values($clusters) as $index=>$cluster) {
            $landmark = $snapshot->landmarks[$cluster['index']];
            $kind = $landmark['definition']->kind;
            $point = $cluster['point'];
            $marker = new CanvasRectangle($point->x-$a->markerSize/2,$point->y-$a->markerSize/2,$a->markerSize,$a->markerSize);
            $category=($landmark['known'] ?? ($landmark['name']!==null)) ? $landmark['definition']->category : null;
            $role = $category===null ? $kind->getIconRole() : MapCategoryCatalog::getIconRole($category);
            if ($cluster['count']===1 && $view->icon('atlas-marker-'.$index,$role,$marker,40)) {
                if ($cluster['selected']) {
                    $indicators[] = new CanvasIndicator('atlas-marker-focus-'.$index,'atlas-map',CanvasIndicatorKind::OUTLINE,
                        $marker,2,$theme->colors['focus'],45);
                }
                continue;
            }
            $color = $cluster['selected'] ? 'focus' : ($landmark['accessible']===false ? 'disabled'
                : ($kind===MapLandmarkKind::BUILDING ? 'increase' : 'accent'));
            $stroke = $cluster['selected'] ? min(3,$a->markerSize) : ($cluster['count']>1 ? 2
                : ($kind->isRoute() ? 2 : (int)ceil($a->markerSize/2)));
            $indicators[] = self::createMarkerHalo('atlas-marker-halo-'.$index,$marker,$theme,39,$width,$height);
            $indicators[] = new CanvasIndicator('atlas-marker-'.$index,'atlas-map',CanvasIndicatorKind::OUTLINE,
                $marker,$stroke,$theme->colors[$color],40);
            $pictograms[]=['point'=>$point,'category'=>$category===null
                ? ($snapshot->global ? 'building' : ($kind->isRoute() ? 'route' : $kind->value))
                : $landmark['definition']->categoryBase,'color'=>$theme->colors[$color]];
        }
        $playerX = (int)floor($snapshot->playerX);
        $playerY = (int)floor($snapshot->playerY);
        $text = [];
        if ($playerX>=0 && $playerY>=0 && $playerX<$snapshot->overview->width && $playerY<$snapshot->overview->height
            && $projection->containsCell($playerX,$playerY)) {
            $point = $projection->projectCell($playerX,$playerY);
            $marker = new CanvasRectangle($point->x-$a->markerSize/2,$point->y-$a->markerSize/2,$a->markerSize,$a->markerSize);
            if (!$view->icon('atlas-player',self::PLAYER_ICON_ROLE,$marker,50)) {
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
        $legendIcons=null;
        if ($full) {
            $legend = new CanvasRectangle($mapBox->x+$mapBox->width+$m->sectionGap,$mapBox->y,$legendWidth,$bodyHeight);
            $legendMarkers=self::renderLegend($view,$snapshot,$legend,$selected,$detailPage);
            $legendIcons=MapAtlasPictograms::createLayer($legendMarkers,$legend,$theme,true,'atlas-legend-pictograms');
        }
        if ($hintHeight>0) {
            $view->hints('atlas-hints',$hints,new CanvasRectangle($box->x+$padding,$box->y+$h-$padding-$hintHeight,
                $contentWidth,$hintHeight),required:$full);
        }
        $view->protect($box);
        $base = $view->finish();
        CanvasImagePreflight::inspect($base->images,$theme->assetRoot,[$raster]);
        $icons=MapAtlasPictograms::createLayer($pictograms,$mapBox,$theme,$full);
        return new PresentationCanvas($width,$height,$base->images,$indicators,[...$base->textLayers,...$text,
            ...($icons===null ? [] : [$icons]),...($legendIcons===null ? [] : [$legendIcons])],[$raster],
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
        int $selected,int $detailPage): array
    {
        $m = $view->theme->metrics;
        $columns = max(1,(int)floor($bounds->width/$m->cellWidth));
        $rows = max(1,(int)floor($bounds->height/$m->cellHeight));
        $selected = max(-1,min(count($snapshot->landmarks)-1,$selected));
        $lines = $rows>=7 ? [['text'=>MessageCatalog::getTerm('map.legend'),'color'=>'accent']] : [];
        $legendMarkers=[];
        if ($rows>=7) {
            $types=$snapshot->global ? ['places'=>['name'=>MessageCatalog::getTerm('map.locations'),'category'=>'building']]
                : ['route'=>['name'=>MessageCatalog::getTerm('map.passage'),'category'=>'route']];
            foreach ($snapshot->landmarks as $landmark) {
                $definition=$landmark['definition'];
                if ($definition->categoryBase===null || !($landmark['known'] ?? ($landmark['name']!==null))) { continue; }
                $types[$definition->category]=['name'=>$definition->getCategoryName(),'category'=>$definition->categoryBase];
            }
            foreach (array_slice($types,0,max(0,$rows-5)) as $type) {
                $legendMarkers[]=['point'=>new \Ichiloto\Engine\Core\Vector2($bounds->x+10,
                    $bounds->y+(count($lines)+0.5)*$m->cellHeight),'category'=>$type['category'],
                    'color'=>$view->theme->colors['accent']];
                $lines[]=['text'=>'   '.TerminalText::truncateToWidth($type['name'],max(1,$columns-3)),'color'=>'text'];
            }
            $lines[]=['text'=>'','color'=>'text'];
        }
        if ($snapshot->playerX<0 && $rows>2) {
            foreach (array_slice(MenuTextWrap::lines(MessageCatalog::getTerm('map.position_unmarked'),$columns),
                0,max(0,$rows-count($lines)-2)) as $line) { $lines[]=['text'=>$line,'color'=>'text']; }
        }
        $focusLines=[];
        $focusRow=count($lines);
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
            $focusRow=count($lines);
            foreach (array_slice($details,$page*$pageSize,$pageSize) as $line) { $focusLines[] = ['text'=>$line,'color'=>'focus']; }
        }
        $view->textLines('atlas-legend',array_slice(array_pad($lines,$rows,['text'=>'','color'=>'text']),0,$rows),$bounds);
        if ($focusLines!==[]) {
            $view->textLines('atlas-focus',$focusLines,new CanvasRectangle($bounds->x,$bounds->y+$focusRow*$m->cellHeight,
                $bounds->width,max($m->cellHeight,$bounds->height-$focusRow*$m->cellHeight)));
        }
        return $legendMarkers;
    }
}
