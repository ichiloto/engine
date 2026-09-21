<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\ControlHint;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use RuntimeException;

/** Standalone control display for lookups/buttons, independent of optional helper strips. */
final class ControlGlyphPresentation
{
  public static function cells(?ControlHint $control, MenuPresentationCatalog $theme): int
  {
    return self::asset($control, $theme) === null
      ? mb_strlen($control?->label ?? 'Unbound') + 2
      : (int)ceil($theme->rows->metrics->iconWidth / $theme->metrics->cellWidth);
  }

  public static function compose(int $width, int $height, string $id, ?ControlHint $control,
    MenuPresentationCatalog $theme, CanvasRectangle $bounds): PresentationCanvas
  {
    $m = $theme->metrics;
    $cells = self::cells($control, $theme);
    if ($cells * $m->cellWidth > $bounds->width || $m->cellHeight > $bounds->height) {
      throw new RuntimeException('Control glyph exceeds its finite viewport.');
    }
    $box = new CanvasRectangle($bounds->x + ($bounds->width - $cells * $m->cellWidth) / 2,
      $bounds->y + ($bounds->height - $m->cellHeight) / 2, $cells * $m->cellWidth, $m->cellHeight);
    $asset = self::asset($control, $theme);
    if ($asset !== null) {
      return new PresentationCanvas($width, $height,
        MenuIconRegistry::containAsset($theme->assetRoot, $id, $asset, $box, 30, $bounds));
    }
    return new PresentationCanvas($width, $height, textLayers: [new CanvasTextLayer($id, 30, $box->x, $box->y,
      new RendererGridConfig($cells, 1, $m->cellWidth, $m->cellHeight),
      [new PresentationTextRun(0, 0, ' ' . ($control?->label ?? 'Unbound') . ' ',
        $theme->colors[$control === null ? 'disabled' : 'text'], $control === null ? null : $theme->colors['selected'])], $bounds)]);
  }

  private static function asset(?ControlHint $control, MenuPresentationCatalog $theme): ?string
  {
    return $control === null ? null : ($theme->icons?->icons[$control->iconRole()] ?? null);
  }
}
