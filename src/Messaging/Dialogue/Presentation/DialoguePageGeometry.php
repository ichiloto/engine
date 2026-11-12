<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Dialogue\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\UI\Presentation\MenuCanvas;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition;
use RuntimeException;

/** One geometry contract for drawing dialogue and reserving its camera space. */
final readonly class DialoguePageGeometry
{
  public CanvasRectangle $body;
  public ?CanvasRectangle $nameplate;
  public ?CanvasRectangle $portrait;
  public float $textX;
  public float $textWidth;
  public array $nameLines;
  public float $nameHeight;

  public function __construct(DialoguePageLayout $layout, DialoguePresentationCatalog $catalogue,
    string $speaker, WindowPosition $position, int $width, int $height, bool $skit = false)
  {
    $m = ($catalogue->theme ?? throw new RuntimeException('Dialogue has no graphical theme.'))->metrics;
    $this->textX = $layout->margin + $m->panelPadding + $layout->gutter;
    $this->textWidth = $width - 2 * ($layout->margin + $m->panelPadding) - $layout->gutter;
    $this->nameLines = $speaker === '' ? [] : MenuCanvas::wrap($speaker, (int)floor(($this->textWidth - 32) / $m->cellWidth));
    $this->nameHeight = count($this->nameLines) * $m->cellHeight + ($this->nameLines === [] ? 0 : 24);
    $top = match ($skit ? WindowPosition::BOTTOM : $position) {
      WindowPosition::TOP => 32 + max(0, $this->nameHeight - 12),
      WindowPosition::MIDDLE => ($height - $layout->bodyHeight) / 2,
      WindowPosition::BOTTOM => $height - ($skit ? 24 : 28) - $layout->bodyHeight,
    };
    if ($top < max(0, $this->nameHeight - 12) || $top + $layout->bodyHeight > $height) {
      throw new RuntimeException('Dialogue content exceeds its readable viewport; terminal presentation retained.');
    }
    $this->body = new CanvasRectangle($layout->margin, $top, $width - 2 * $layout->margin, $layout->bodyHeight);
    $nameWidth = $this->nameLines === [] ? 0 : min($this->textWidth,
      max(128, max(array_map(mb_strlen(...), $this->nameLines)) * $m->cellWidth + 32));
    $this->nameplate = $nameWidth === 0 ? null : new CanvasRectangle($this->textX,
      $top - $this->nameHeight + 12, $nameWidth, $this->nameHeight);
    $compact = $width < 1000;
    $this->portrait = $layout->portrait === null ? null : new CanvasRectangle($layout->margin + $m->panelPadding,
      max(0, $top - 36), $compact ? 116 : 180, min($compact ? 154 : 222, $layout->bodyHeight + 8));
  }

  /** @return list<CanvasRectangle> */
  public function getProtectedBounds(): array { return array_values(array_filter([$this->body, $this->nameplate, $this->portrait])); }
}
