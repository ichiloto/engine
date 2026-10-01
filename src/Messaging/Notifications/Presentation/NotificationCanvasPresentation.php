<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Notifications\Presentation;

use Ichiloto\Engine\Messaging\Notifications\Interfaces\GraphicalNotificationInterface;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\UI\Presentation\MenuCanvas;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;

/** Live text in the existing panel skin. Never truncates counts, titles or notification bodies. */
final class NotificationCanvasPresentation
{
  public static function compose(GraphicalNotificationInterface $notice, MenuPresentationCatalog $theme,
    int $width, int $height, array $protected = [], ?CanvasRectangle $anchor = null): ?NotificationSurface
  {
    $style = $theme->notifications;
    $compact = $width < 1000 || $height < 600;
    $margin = $compact ? min(16, $style->margin) : $style->margin;
    $padding = $compact ? min(20, $style->padding) : $style->padding;
    $icon = $compact ? min(26, $style->iconSize) : $style->iconSize;
    $gap = $compact ? min(12, $style->iconGap) : $style->iconGap;
    $cw = $theme->metrics->cellWidth;
    $ch = $theme->metrics->cellHeight;
    $role = $notice->getPresentationRole();
    $iconRole = 'notification.' . $role;
    if (!isset($theme->icons?->icons[$iconRole])) { $iconRole = 'notification.' . strtolower($notice->getChannel()->value); }
    $hasIcon = isset($theme->icons?->icons[$iconRole]);
    $iconSpace = $hasIcon ? $icon + $gap : 0;
    $limit = min($height - 2 * $margin, $height * ($compact ? max(0.45, $style->maxHeightRatio) : $style->maxHeightRatio));
    $sizes = array_unique([min($compact ? 352 : $style->width, $width - 2 * $margin),
      min($style->maxWidth, $width - 2 * $margin)]);
    foreach ($sizes as $w) {
      $columns = (int)floor(($w - 2 * $padding - $iconSpace) / $cw);
      if ($columns < 1) { continue; }
      $title = MenuCanvas::wrap($notice->getContentTitle(), $columns);
      $body = MenuCanvas::wrap($notice->getContentText(), $columns);
      $textHeight = count($title) * $ch + $style->textGap + count($body) * $ch;
      $h = 2 * $padding + max($icon, $textHeight);
      if ($h > $limit) { continue; }
      $candidates = $anchor === null
        ? [new CanvasRectangle($width - $margin - $w, $margin, $w, $h), new CanvasRectangle($margin, $margin, $w, $h)]
        : [new CanvasRectangle(min($anchor->x, max(0, $width - $w - $margin)), $anchor->y, $w, $h)];
      foreach ($candidates as $box) {
        if ($box->y + $h > $height || !NotificationPlacement::isClear($box, $protected)) { continue; }
        $view = new MenuCanvas($theme, $width, $height);
        $id = $notice->getPresentationId();
        $view->frame($id . '-frame', $box);
        if ($hasIcon) { $view->icon($id . '-icon', $iconRole, new CanvasRectangle($box->x + $padding,
          $box->y + $padding, $icon, $icon)); }
        $canvas = $view->finish();
        $opacity = $notice->getPresentationOpacity();
        $images = array_map(static fn($image) => new CanvasImage($image->id, $image->asset, $image->destination,
          $image->layer, $image->sourceRect, $image->opacity * $opacity, $image->clipRect, $image->brightness), $canvas->images);
        $text = [];
        foreach ($canvas->textLayers as $layer) {
          if ($layer->id === 'menu-background' || str_starts_with($layer->id, 'menu-background-part-')) { continue; }
          $text[] = new CanvasTextLayer($layer->id, $layer->layer, $layer->x, $layer->y,
            $layer->grid, $layer->runs, $layer->clipRect, $layer->opacity * $opacity);
        }
        $x = $box->x + $padding + $iconSpace;
        $y = $box->y + $padding;
        $titleColor = $style->colors[$role] ?? $style->colors[strtolower($notice->getChannel()->value)] ?? $theme->colors['accent'];
        foreach (['title' => $title, 'body' => $body] as $kind => $lines) {
          $runs = [];
          foreach ($lines as $row => $line) {
            if ($line !== '') { $runs[] = new PresentationTextRun($row, 0, $line, $kind === 'title' ? $titleColor : $theme->colors['text']); }
          }
          $text[] = new CanvasTextLayer($id . '-' . $kind, 30, $x, $y,
            new RendererGridConfig($columns, count($lines), $cw, $ch), $runs, $box, $opacity);
          $y += count($lines) * $ch + $style->textGap;
        }
        return new NotificationSurface(new PresentationCanvas($width, $height, $images, textLayers: $text), $box);
      }
      // A fitting size with no safe corner is deferred, not moved or shrunk across protected content.
      return null;
    }
    throw new NotificationContentOverflow('Notification content exceeds the finite readable viewport; full content remains queued.');
  }
}
