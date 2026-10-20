<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\Text\TextPage;
use Ichiloto\Engine\UI\Text\MenuInfoText;
use RuntimeException;

/** Summon contracts use the shared menu frames, records, text viewport and semantic hints. */
final class SummonsMenuPresentation
{
  /** The owner pages Info through this same finite viewport for semantic scrolling. */
  public static function getDetailViewport(SummonsMenuContent $content, MenuPresentationCatalog $theme,
    int $width = PresentationCanvas::DEFAULT_WIDTH, int $height = PresentationCanvas::DEFAULT_HEIGHT): MenuDetailViewport
  {
    $body = self::getGeometry($content, $theme, $width, $height)['body'];
    $m = $theme->metrics;
    return new MenuDetailViewport((int)floor(($body->width - 2 * $m->panelPadding) / $m->cellWidth),
      (int)floor($body->height - 2 * $m->panelPadding), $m->cellHeight);
  }

  public static function compose(SummonsMenuContent $content, MenuPresentationCatalog $theme, ?TextPage $page,
    ?MenuInfoText $infoModel = null, float $time = 0,
    int $width = PresentationCanvas::DEFAULT_WIDTH, int $height = PresentationCanvas::DEFAULT_HEIGHT): PresentationCanvas
  {
    $g = self::getGeometry($content, $theme, $width, $height);
    $m = $theme->metrics;
    $p = $m->panelPadding;
    $view = new MenuCanvas($theme, $width, $height, $time);
    $view->frame('summons-summary', $g['summary']);
    $view->prose('summons-title', $content->title, $g['title'], 'accent');
    $view->prose('summons-character', implode("\n", $content->summary), $g['identity']);
    if ($g['portrait'] !== null && $content->character !== null) { $view->portrait($content->character->actorId, $g['portrait'], 'summons-portrait'); }
    $view->frame('summons-contracts', $g['body']);
    $body = new CanvasRectangle($g['body']->x + $p, $g['body']->y + $p,
      $g['body']->width - 2 * $p, $g['body']->height - 2 * $p);
    if ($content->detailsOpen) {
      $area = self::getDetailViewport($content, $theme, $width, $height);
      if ($page === null || $content->details === null || $page->columns !== $area->columns || $page->rows !== $area->height) {
        throw new RuntimeException('Summon contract page must match its measured viewport.');
      }
      $view->details('summons-detail', $content->details->layout($area->columns, $theme->details), $page, $body);
      if ($page->total > count($page->lines)) {
        $view->renderBorderCaption('summons-detail-range', $page->range(),
          new CanvasRectangle($body->x, $g['body']->y + $g['body']->height - $p, $body->width, $p));
      }
    } elseif ($content->rows === []) {
      $view->prose('summons-empty', $content->character === null ? 'No summons.' : 'No assignable summons.', $body, 'disabled');
    } else {
      $cells = (int)floor(($body->width - 2 * $theme->rows->metrics->padding) / $m->cellWidth);
      $valueWidth = max(1, (int)floor($cells / 4));
      $view->rows('summons-entry', $content->rows,
        new MenuRowLayout($body, [new MenuRowColumn($valueWidth), new MenuRowColumn($valueWidth)],
          $m->rowHeight, $m->cellWidth, $m->cellHeight, true), $content->index);
    }
    $view->frame('summons-info', $g['info'], 'quiet');
    MenuInfoPanel::renderContent($view, 'summons', $g['description'], $content->description, $content->status,
      infoModel: $infoModel,
      rangeBounds: new CanvasRectangle($g['description']->x, $g['info']->y + $g['info']->height - $p, $body->width, $p));
    if ($g['hints'] !== null) { $view->hints('summons-hints', $content->hints, $g['hints']); }
    return $view->finish();
  }

  private static function getGeometry(SummonsMenuContent $content, MenuPresentationCatalog $theme, int $width, int $height): array
  {
    $host = MenuLayout::getBounds($width, $height);
    $m = $theme->metrics;
    $p = $m->panelPadding;
    $innerWidth = $host->width - 2 * $p;
    $portrait = $content->character !== null && isset($theme->portraits[$content->character->actorId]) ? $m->portraitSize : 0;
    $textWidth = $innerWidth - ($portrait > 0 ? $portrait + $m->sectionGap : 0);
    $titleHeight = count(MenuCanvas::wrap($content->title, (int)floor($textWidth / $m->cellWidth))) * $m->cellHeight;
    $identityHeight = count(MenuCanvas::wrap(implode("\n", $content->summary), (int)floor($textWidth / $m->cellWidth))) * $m->cellHeight;
    $summaryHeight = 2 * $p + max($portrait, $titleHeight + $identityHeight + $m->sectionGap);
    $infoHeight = MenuInfoPanel::getHeight($theme, $content->hints, $host->width);
    $bodyHeight = $host->height - $summaryHeight - $infoHeight;
    if ($bodyHeight < 2 * $p + $m->rowHeight) { throw new RuntimeException('Summons menu needs a complete row in its finite viewport.'); }
    $summary = new CanvasRectangle($host->x, $host->y, $host->width, $summaryHeight);
    $info = new CanvasRectangle($host->x, $host->y + $host->height - $infoHeight, $host->width, $infoHeight);
    $textX = $host->x + $p + ($portrait > 0 ? $portrait + $m->sectionGap : 0);
    $hintHeight = MenuActionHints::height($content->hints, $theme, $innerWidth);
    return ['summary' => $summary,
      'title' => new CanvasRectangle($textX, $host->y + $p, $textWidth, $titleHeight),
      'identity' => new CanvasRectangle($textX, $host->y + $p + $titleHeight + $m->sectionGap, $textWidth, $identityHeight),
      'portrait' => $portrait > 0 ? new CanvasRectangle($host->x + $p, $host->y + $p, $portrait, $portrait) : null,
      'body' => new CanvasRectangle($host->x, $host->y + $summaryHeight, $host->width, $bodyHeight),
      'info' => $info,
      'description' => new CanvasRectangle($host->x + $p, $info->y + $p, $innerWidth, 2 * $m->cellHeight),
      'hints' => $hintHeight > 0 ? new CanvasRectangle($host->x + $p,
        $info->y + $p + 2 * $m->cellHeight + $m->sectionGap, $innerWidth, $hintHeight) : null];
  }
}
