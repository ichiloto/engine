<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

/** Common menu envelope and control sizing, independent of any game's artwork. */
final class MenuLayout
{
  public static function getBounds(int $width = 1350, int $height = 720): CanvasRectangle
  {
    $w = min(1100, $width - 20);
    $h = min(700, $height - 20);
    return new CanvasRectangle(($width - $w) / 2, ($height - $h) / 2, $w, $h);
  }

  public static function getButtonWidth(MenuPresentationCatalog $theme, string $label, float $available): float
  {
    return min($available, max(12 * $theme->metrics->cellWidth,
      mb_strlen($label) * $theme->metrics->cellWidth + 2 * $theme->rows->metrics->padding));
  }
}
