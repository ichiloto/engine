<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use InvalidArgumentException;

/** The owner supplies the viewport and visible subset; this class does not paginate. */
final readonly class MenuRowLayout
{
  /** @var list<MenuRowColumn> */
  public array $columns;

  /** @param list<MenuRowColumn> $columns */
  public function __construct(
    public CanvasRectangle $viewport,
    array $columns = [],
    public int $rowHeight = 40,
    public int $cellWidth = 10,
    public int $cellHeight = 24,
    public bool $wrapText = false,
  ) {
    new RendererGridConfig(1, 1, $cellWidth, $cellHeight);
    if ($rowHeight < $cellHeight || $rowHeight > 256 || !array_is_list($columns)) {
      throw new InvalidArgumentException('Menu row geometry must fit its Canvas text height.');
    }
    $copy = [];
    foreach ($columns as $column) {
      if (!$column instanceof MenuRowColumn) { throw new InvalidArgumentException('Menu columns must be typed.'); }
      $copy[] = $column;
    }
    $this->columns = $copy;
  }

  public function capacity(): int
  {
    return (int)floor($this->viewport->height / $this->rowHeight);
  }

  /** Complete scalar text is wrapped, never elided. Values retain their own aligned columns. */
  public function lines(string $text, int $cells): array
  {
    if ($cells < 1 || (!$this->wrapText && mb_strlen($text, 'UTF-8') > $cells)) {
      throw new InvalidArgumentException('Menu text requires wider columns or wrapping; complete text must not be silently truncated.');
    }
    return $text === '' ? [''] : mb_str_split($text, $cells, 'UTF-8');
  }

  public function heightFor(MenuRow $row, MenuRowMetrics $metrics, bool $hasIcon): int
  {
    $iconCells = $hasIcon ? (int)ceil($metrics->iconWidth / $this->cellWidth) + $metrics->gapCells : 0;
    $cells = $row->kind->isAction()
      ? (int)floor(($this->viewport->width - 2 * ($metrics->padding + $iconCells * $this->cellWidth)) / $this->cellWidth)
      : $this->textCells($metrics) - $this->valueCells($metrics) - $iconCells;
    $lines = count($this->lines($row->label, $cells));
    foreach ($row->values as $index => $value) {
      $column = $this->columns[$index] ?? throw new InvalidArgumentException('Menu value column missing.');
      $lines = max($lines, count($this->lines($value->text, $column->cells)));
    }
    return $this->rowHeight + ($lines - 1) * $this->cellHeight;
  }

  public function textCells(MenuRowMetrics $metrics): int
  {
    return (int)floor(($this->viewport->width - 2 * $metrics->padding) / $this->cellWidth);
  }

  public function valueCells(MenuRowMetrics $metrics): int
  {
    return array_sum(array_map(fn(MenuRowColumn $column) => $column->cells + $metrics->gapCells, $this->columns));
  }

  public function assertFits(MenuRowMetrics $metrics): void
  {
    $cells = $this->textCells($metrics);
    if ($cells < 1 || $cells > 512 || $this->valueCells($metrics) >= $cells
      || $metrics->separatorWidth + $this->cellHeight > $this->rowHeight
      || 2 * $metrics->focusWidth >= min($this->rowHeight, $this->viewport->width)
      || $metrics->accentWidth > $this->viewport->width) {
      throw new InvalidArgumentException('Menu columns and theme metrics require a larger row or viewport within Canvas limits.');
    }
  }
}
