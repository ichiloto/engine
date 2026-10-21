<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use InvalidArgumentException;

/**
 * Width in existing Canvas text cells; shared by headings and record values.
 * A labelled column holds its label in a fixed place at its left and each
 * row's figure aligned in the cells after it ("HP  90/90", "Lv 12"), so
 * rows line up whatever their figures. Every presentation lays a cell out
 * the same way ({@see formatCell()}): graphical rows and the Terminal alike.
 */
final readonly class MenuRowColumn
{
  /** @param ?int $gap Cells before the column, wider than the theme's where columns need telling apart; the theme's when null. */
  public function __construct(public int $cells, public HorizontalAlignment $alignment = HorizontalAlignment::RIGHT,
    public ?string $label = null, public ?int $gap = null)
  {
    if ($gap !== null && ($gap < 0 || $gap > 8)) { throw new InvalidArgumentException('A menu column keeps 0..8 cells before it.'); }
    if ($cells < 1 || $cells > RendererGridConfig::MAX_COLUMNS) {
      throw new InvalidArgumentException(sprintf('Menu value columns require 1..%d cells.', RendererGridConfig::MAX_COLUMNS));
    }
    if ($label !== null && (trim($label) === '' || mb_strlen($label, 'UTF-8') + 2 > $cells || str_contains($label, "\n"))) {
      throw new InvalidArgumentException('A labelled menu column holds its one-line label, a space and at least one cell of figure.');
    }
  }

  /** A column just wide enough for every figure given, after its label when it has one. */
  public static function fit(array $figures, HorizontalAlignment $alignment = HorizontalAlignment::RIGHT, ?string $label = null,
    ?int $gap = null): self
  {
    $widest = max([1, ...array_map(static fn(string $figure): int => mb_strlen($figure, 'UTF-8'), $figures)]);
    return new self(($label === null ? 0 : mb_strlen($label, 'UTF-8') + 1) + $widest, $alignment, $label,
      $gap ?? ($label === null ? null : self::LABELLED_GAP));
  }

  /** Cells before a labelled column, so one stat's figure never reads into the next stat's label. */
  public const int LABELLED_GAP = 2;

  /**
   * One labelled column per field, in the first row's order, each as wide as
   * its label and its widest figure: a party's Lv, HP and MP.
   *
   * @param list<array<string, string>> $rows Each row's figures by label.
   * @return list<self>
   */
  public static function listForFields(array $rows): array
  {
    $labels = array_keys($rows[0] ?? []);
    return array_map(static fn(string|int $label): self => self::fit(array_map(static fn(array $row): string => (string) ($row[$label] ?? ''), $rows),
      HorizontalAlignment::RIGHT, (string) $label), $labels);
  }

  /** The cells a figure has: the column's, less its label and the space after it. */
  public function getFigureCells(): int
  {
    return $this->cells - ($this->label === null ? 0 : mb_strlen($this->label, 'UTF-8') + 1);
  }

  /** A figure as this column lays it out, exactly its cells wide: the label, then the figure aligned after it. */
  public function formatCell(string $figure): string
  {
    $space = max(0, $this->getFigureCells() - mb_strlen($figure, 'UTF-8'));
    $aligned = match ($this->alignment) {
      HorizontalAlignment::LEFT => $figure . str_repeat(' ', $space),
      HorizontalAlignment::CENTER => str_repeat(' ', intdiv($space, 2)) . $figure . str_repeat(' ', $space - intdiv($space, 2)),
      HorizontalAlignment::RIGHT => str_repeat(' ', $space) . $figure,
    };
    return ($this->label === null ? '' : $this->label . ' ') . $aligned;
  }
}
