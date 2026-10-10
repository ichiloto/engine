<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Notifications\Presentation;

use Ichiloto\Engine\Messaging\Notifications\Interfaces\GraphicalNotificationInterface;
use Ichiloto\Engine\Messaging\Notifications\NotificationContentPolicy;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\UI\Presentation\MenuCanvas;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\MenuTextLayout;

/** Live text in the existing panel skin. Never truncates counts, titles or notification bodies. */
final class NotificationCanvasPresentation
{
  public static function compose(GraphicalNotificationInterface $notice, MenuPresentationCatalog $theme,
    int $width, int $height, array $protected = []): NotificationSurface
  {
    if (!NotificationContentPolicy::fitsToast($notice)) {
      throw new NotificationContentOverflow('Long information requires an acknowledged alert, not a transient notice.');
    }
    $style = $theme->notifications;
    $layout = new NotificationLayout($theme, $width, $height);
    $padding = $layout->padding;
    $icon = $layout->iconSize;
    $cw = $theme->metrics->cellWidth;
    $ch = $theme->metrics->cellHeight;
    $role = $notice->getPresentationRole();
    $iconRole = 'notification.' . $role;
    if (!isset($theme->icons?->icons[$iconRole])) { $iconRole = 'notification.' . strtolower($notice->getChannel()->value); }
    $hasIcon = isset($theme->icons?->icons[$iconRole]);
    $iconSpace = $hasIcon ? $icon + $layout->iconGap : 0;
    foreach ($layout->widths as $w) {
      $columns = (int)floor(($w - 2 * $padding - $iconSpace) / $cw);
      if ($columns < 1) { continue; }
      $title = new MenuTextLayout($notice->getContentTitle(), $columns);
      $body = new MenuTextLayout($notice->getContentText(), $columns);
      if (count($title->lines) > 1 || count($body->lines) > 2) { continue; }
      $h = $layout->getHeight(count($title->lines), count($body->lines), $hasIcon);
      if ($h > $layout->heightLimit) { continue; }
      $box = $layout->getBounds($w, $h);
      $view = new MenuCanvas($theme, $width, $height, background: false);
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
      foreach (['title' => $title, 'body' => $body] as $kind => $textLayout) {
        $runs = $textLayout->getRuns($kind === 'title' ? $titleColor : $theme->colors['text']);
        $text[] = new CanvasTextLayer($id . '-' . $kind, 30, $x, $y,
          new RendererGridConfig($columns, count($textLayout->lines), $cw, $ch), $runs, $box, $opacity);
        $y += count($textLayout->lines) * $ch + $style->textGap;
      }
      return new NotificationSurface(new PresentationCanvas($width, $height, $images, textLayers: $text,
        protectedAreas: [$box]), $box);
    }
    throw new NotificationContentOverflow('Notification cannot fit its terse reading area; route the full content to an alert.');
  }
}
