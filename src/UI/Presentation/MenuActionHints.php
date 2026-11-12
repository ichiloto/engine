<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\ActionHint;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use InvalidArgumentException;
use RuntimeException;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;

/** Inline semantic hints, shared by menus: each control drawn by ControlGlyphPresentation, then its action's label. */
final class MenuActionHints
{
  /** Cells between one hint and the next. */
  private const int GAP = 3;
  private const string PAIR_SEPARATOR = ' / ';

  /**
   * @param list<ActionHint> $hints
   * @param array{int, int}|null $cell A text cell (width, height) for a screen that sets its hints' size; the theme's otherwise.
   */
  public static function height(array $hints, MenuPresentationCatalog $theme, float $width, bool $required = false, ?array $cell = null): int
  {
    if (!$required && !$theme->showInputHints) { return 0; }
    return self::layout($hints, $theme, $width, $cell)['rows'] * ($cell[1] ?? $theme->metrics->cellHeight);
  }

  /**
   * How many cells the hints take on one row, so an owner can align the strip (to the right, say) before composing it.
   *
   * @param list<ActionHint> $hints
   */
  public static function measureCells(array $hints, MenuPresentationCatalog $theme, ?array $cell = null): int
  {
    return self::layout($hints, $theme, 4096 * ($cell[0] ?? $theme->metrics->cellWidth), $cell)['used'];
  }

  /**
   * @param list<ActionHint> $hints
   * @param int $layer The canvas layer of the keys; their names and labels draw one above.
   */
  public static function compose(int $width, int $height, string $id, array $hints,
    MenuPresentationCatalog $theme, CanvasRectangle $bounds, bool $required = false, ?array $cell = null, int $layer = 30): PresentationCanvas
  {
    if (!$required && !$theme->showInputHints) { return new PresentationCanvas($width, $height); }
    $layout = self::layout($hints, $theme, $bounds->width, $cell);
    [$cellWidth, $cellHeight] = $cell ?? [$theme->metrics->cellWidth, $theme->metrics->cellHeight];
    if ($layout['rows'] * $cellHeight > $bounds->height) {
      throw new RuntimeException('Action hints exceed their finite viewport; terminal presentation retained.');
    }
    $controls = new ControlGlyphPresentation($theme, $id, [$cellWidth, $cellHeight], $layer);
    foreach ($layout['controls'] as $index => $placed) {
      $controls->renderControl($id . '-control-' . $index, $placed['control'], new CanvasRectangle(
        $bounds->x + $placed['column'] * $cellWidth, $bounds->y + $placed['row'] * $cellHeight,
        $placed['cells'] * $cellWidth, $cellHeight), HorizontalAlignment::LEFT);
    }
    foreach ($layout['runs'] as $run) {
      $controls->renderText($run->text, $bounds->x + $run->column * $cellWidth, $bounds->y + $run->row * $cellHeight, $run->foreground);
    }
    $drawn = $controls->compose($width, $height);
    $protected = [];
    for ($row = 0; $row < $layout['rows']; $row++) {
      $protected[] = new CanvasRectangle($bounds->x, $bounds->y + $row * $cellHeight, $layout['used'] * $cellWidth, $cellHeight);
    }
    return new PresentationCanvas($width, $height, $drawn->images, textLayers: $drawn->textLayers, protectedAreas: $protected);
  }

  /** Measurement and painting use the same already-resolved snapshot, even when profiles change.
   * Each hint is its control (or a previous/next pair of them) followed by the action's label.
   * @param list<ActionHint> $hints
   * @return array{cells:int, rows:int, runs:list<PresentationTextRun>, controls:list<array{control:?\Ichiloto\Engine\IO\ControlHint, row:int, column:int, cells:int}>, used:int}
   */
  private static function layout(array $hints, MenuPresentationCatalog $theme, float $width, ?array $cell = null): array
  {
    if (!array_is_list($hints)) { throw new InvalidArgumentException('Action hints must be an ordered list.'); }
    $cellWidth = $cell[0] ?? $theme->metrics->cellWidth;
    $cells = (int)floor($width / $cellWidth);
    if ($cells < 1) { throw new RuntimeException('Action hints have no available width.'); }
    $measure = new ControlGlyphPresentation($theme, 'hints', $cell);
    $row = $column = 0;
    $runs = $controls = [];
    $append = static function (string $text) use (&$runs, &$row, &$column, $cells, $theme): void {
      while ($text !== '') {
        if ($column === $cells) { $column = 0; $row++; }
        $part = mb_substr($text, 0, $cells - $column, 'UTF-8');
        $length = mb_strlen($part, 'UTF-8');
        if (trim($part) !== '') { $runs[] = new PresentationTextRun($row, $column, $part, $theme->colors['disabled']); }
        $column += $length;
        $text = mb_substr($text, $length, null, 'UTF-8');
      }
    };
    foreach ($hints as $hint) {
      if (!$hint instanceof ActionHint) { throw new InvalidArgumentException('Action hints require typed descriptors.'); }
      // A previous/next pair reads as two keys sharing one label ("Q / E Suits").
      $parts = $hint->pairedAction === null ? [$hint->control] : [$hint->control, $hint->pairedControl];
      $partCells = array_map($measure->measureCells(...), $parts);
      $controlCells = array_sum($partCells) + (count($parts) - 1) * mb_strlen(self::PAIR_SEPARATOR, 'UTF-8');
      if ($controlCells > $cells) { throw new RuntimeException('Action hint controls exceed their available width.'); }
      $length = $controlCells + 1 + mb_strlen($hint->label, 'UTF-8');
      if ($column > 0) {
        if ($column + self::GAP + $length > $cells) { $column = 0; $row++; }
        else { $column += self::GAP; }
      }
      // The controls of a hint stay together on one line.
      if ($column + $controlCells > $cells) { $column = 0; $row++; }
      foreach ($parts as $index => $control) {
        if ($index > 0) { $append(self::PAIR_SEPARATOR); }
        $controls[] = ['control' => $control, 'row' => $row, 'column' => $column, 'cells' => $partCells[$index]];
        $column += $partCells[$index];
      }
      $append(' ' . $hint->label);
    }
    $used = 0;
    foreach ($runs as $run) { $used = max($used, $run->column + mb_strlen($run->text, 'UTF-8')); }
    foreach ($controls as $control) { $used = max($used, $control['column'] + $control['cells']); }
    return ['cells' => $cells, 'rows' => $hints === [] ? 0 : $row + 1, 'runs' => $runs, 'controls' => $controls, 'used' => $used];
  }
}
