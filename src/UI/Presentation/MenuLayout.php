<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;

/** Common menu envelope and control sizing, independent of any game's artwork. */
final class MenuLayout
{
  /** Full menus share a reading envelope with an outer margin on smaller surfaces. */
  public const int MAX_WIDTH = 1100;
  public const int MAX_HEIGHT = 700;
  public const int OUTER_MARGIN = 10;
  private const int MIN_BUTTON_CELLS = 12;

  public static function getBounds(int $width = PresentationCanvas::DEFAULT_WIDTH,
    int $height = PresentationCanvas::DEFAULT_HEIGHT, int $maxHeight = self::MAX_HEIGHT): CanvasRectangle
  {
    $w = min(self::MAX_WIDTH, $width - 2 * self::OUTER_MARGIN);
    $h = min($maxHeight, $height - 2 * self::OUTER_MARGIN);
    return new CanvasRectangle(($width - $w) / 2, ($height - $h) / 2, $w, $h);
  }

  public static function getButtonWidth(MenuPresentationCatalog $theme, string $label, float $available): float
  {
    return min($available, max(self::MIN_BUTTON_CELLS * $theme->metrics->cellWidth,
      mb_strlen($label) * $theme->metrics->cellWidth + 2 * $theme->rows->metrics->padding));
  }
}
