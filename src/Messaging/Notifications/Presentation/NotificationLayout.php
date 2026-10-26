<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Notifications\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;

/** One viewport-relative anchor and measurement policy for notices and menu safe areas. */
final readonly class NotificationLayout
{
  public int $margin;
  public int $padding;
  public int $iconSize;
  public int $iconGap;
  public float $heightLimit;
  /** @var list<int> */
  public array $widths;

  public function __construct(private MenuPresentationCatalog $theme, private int $width, private int $height)
  {
    $style = $theme->notifications;
    $compact = $width < 1000 || $height < 600;
    $this->margin = $compact ? min(16, $style->margin) : $style->margin;
    $this->padding = $compact ? min(20, $style->padding) : $style->padding;
    $this->iconSize = $compact ? min(26, $style->iconSize) : $style->iconSize;
    $this->iconGap = $compact ? min(12, $style->iconGap) : $style->iconGap;
    $availableWidth = max(1, $width - 2 * $this->margin);
    $this->widths = array_values(array_unique([min($compact ? 352 : $style->width, $availableWidth),
      min($style->maxWidth, $availableWidth)]));
    $this->heightLimit = min($height - 2 * $this->margin,
      $height * ($compact ? max(0.45, $style->maxHeightRatio) : $style->maxHeightRatio));
  }

  public function getHeight(int $titleLines, int $bodyLines, bool $hasIcon = true): int
  {
    return 2 * $this->padding + max($hasIcon ? $this->iconSize : 0,
      ($titleLines + $bodyLines) * $this->theme->metrics->cellHeight + $this->theme->notifications->textGap);
  }

  public function getBounds(float $width, float $height): CanvasRectangle
  {
    return new CanvasRectangle($this->width - $this->margin - $width, $this->margin, $width, $height);
  }

  /** Menus reserve the whole terse notice budget without moving when it appears. */
  public function getReservedBounds(): CanvasRectangle
  {
    return $this->getBounds(max($this->widths), $this->getHeight(1, 2));
  }
}
