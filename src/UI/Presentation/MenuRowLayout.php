<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
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
    public bool $ellipsis = false,
  ) {
    new RendererGridConfig(1, 1, $cellWidth, $cellHeight);
    if ($rowHeight < $cellHeight || $rowHeight > RendererGridConfig::MAX_CELL_SIZE || !array_is_list($columns)) {
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
    return MenuTextWrap::lines($text, $cells);
  }

  /**
   * A row's label as its lines: on one line, cut with an ellipsis only when
   * it cannot fit, in a list of one-line rows ($ellipsis); else as text is
   * laid out ({@see lines()}).
   *
   * @return non-empty-list<string>
   */
  public function labelLines(string $text, int $cells): array
  {
    return $this->ellipsis ? [MenuTextWrap::ellipsize($text, max(1, $cells))] : $this->lines($text, $cells);
  }

  /** A value's lines in its column: a labelled column's figure takes the cells after the label. */
  public function valueLines(string $text, MenuRowColumn $column): array
  {
    return $this->lines($text, $column->getFigureCells());
  }

  public function heightFor(MenuRow $row, MenuRowMetrics $metrics, bool $hasIcon): int
  {
    $iconCells = $hasIcon ? (int)ceil($metrics->iconWidth / $this->cellWidth) + $metrics->gapCells : 0;
    $cells = $row->kind->isAction()
      ? (int)floor(($this->viewport->width - 2 * ($metrics->padding + $iconCells * $this->cellWidth)) / $this->cellWidth)
      : $this->getLabelCells($row, $metrics) - $iconCells;
    $lines = count($row->kind->isAction() ? $this->lines($row->label, $cells) : $this->labelLines($row->label, $cells));
    foreach ($row->values as $index => $value) {
      $column = $this->columns[$index] ?? throw new InvalidArgumentException('Menu value column missing.');
      $lines = max($lines, count($this->valueLines($value->text, $column)));
    }
    return $this->rowHeight + ($lines - 1) * $this->cellHeight;
  }

  /**
   * The layout giving every label its whole width: where the widest label
   * and the columns do not fit together, left-aligned text columns give
   * way, from the last, down to a readable width, and their text wraps
   * rather than being cut. Figure columns (right-aligned or labelled) keep
   * their width. Unchanged where everything fits or nothing can give way.
   *
   * @param list<string> $labels The labels of the rows that show figures; a row without one takes the whole width ({@see getLabelCells()}).
   */
  public function withWholeLabels(array $labels, MenuRowMetrics $metrics, int $iconCells = 0): self
  {
    $widest = max([1, ...array_map(static fn(string $label): int => mb_strlen($label, 'UTF-8'), $labels)]) + $iconCells;
    $excess = $widest + $this->valueCells($metrics) - $this->textCells($metrics);
    $columns = $this->columns;
    for ($index = count($columns) - 1; $index >= 0 && $excess > 0; $index--) {
      $column = $columns[$index];
      $give = $column->alignment === HorizontalAlignment::LEFT && $column->label === null
        ? min($excess, $column->cells - self::MIN_TEXT_COLUMN_CELLS) : 0;
      if ($give > 0) {
        $columns[$index] = new MenuRowColumn($column->cells - $give, $column->alignment, null, $column->gap);
        $excess -= $give;
      }
    }

    return $columns === $this->columns ? $this
      : new self($this->viewport, $columns, $this->rowHeight, $this->cellWidth, $this->cellHeight, true, $this->ellipsis);
  }

  /**
   * The cells a row's label may take: those the value columns leave, or the
   * whole row where it has no figure to show, since nothing sits beside it.
   */
  public function getLabelCells(MenuRow $row, MenuRowMetrics $metrics): int
  {
    $figures = array_any($row->values, static fn(MenuRowValue $value): bool => $value->text !== '' || $value->direction !== null);

    return $this->textCells($metrics) - ($figures ? $this->valueCells($metrics) : 0);
  }

  /** The narrowest a text column gives way to, so its words stay readable when they wrap. */
  public const int MIN_TEXT_COLUMN_CELLS = 12;

  public function textCells(MenuRowMetrics $metrics): int
  {
    return (int)floor(($this->viewport->width - 2 * $metrics->padding) / $this->cellWidth);
  }

  public function valueCells(MenuRowMetrics $metrics): int
  {
    return array_sum(array_map(fn(MenuRowColumn $column) => $column->cells + ($column->gap ?? $metrics->gapCells), $this->columns));
  }

  public function assertFits(MenuRowMetrics $metrics, bool $hasSeparateTreatment = false): void
  {
    $cells = $this->textCells($metrics);
    if ($cells < 1 || $cells > RendererGridConfig::MAX_COLUMNS || $this->valueCells($metrics) >= $cells
      || (!$hasSeparateTreatment && $metrics->separatorWidth + $this->cellHeight > $this->rowHeight)
      || 2 * $metrics->focusWidth >= min($this->rowHeight, $this->viewport->width)
      || $metrics->accentWidth > $this->viewport->width) {
      throw new InvalidArgumentException('Menu columns and theme metrics require a larger row or viewport within Canvas limits.');
    }
  }
}
