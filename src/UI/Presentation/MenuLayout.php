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
    $cell = $theme->metrics->getButtonCellWidth();
    return min($available, max($theme->metrics->buttonMinWidth, self::MIN_BUTTON_CELLS * $cell,
      mb_strlen($label) * $cell + 2 * $theme->rows->metrics->padding));
  }

  public static function getButtonHeight(MenuPresentationCatalog $theme, ?int $preferred = null): int
  {
    $metrics = $theme->metrics;
    return max($preferred ?? $metrics->rowHeight, $metrics->buttonMinHeight,
      (int)ceil($metrics->getButtonCellHeight() + $theme->rows->metrics->separatorWidth));
  }

  public static function getButtonLayout(MenuPresentationCatalog $theme, CanvasRectangle $bounds,
    ?int $preferredHeight = null, bool $wrapText = false): MenuRowLayout
  {
    return new MenuRowLayout($bounds, rowHeight: min((int)floor($bounds->height), self::getButtonHeight($theme, $preferredHeight)),
      cellWidth: $theme->metrics->getButtonCellWidth(), cellHeight: $theme->metrics->getButtonCellHeight(),
      wrapText: $wrapText);
  }
}
