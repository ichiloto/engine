<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\ActionHint;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use InvalidArgumentException;
use RuntimeException;

/** Inline semantic hints, shared by menus. Glyphs replace controls, never actions or input behavior. */
final class MenuActionHints
{
  /** @param list<ActionHint> $hints */
  public static function height(array $hints, MenuPresentationCatalog $theme, float $width): int
  {
    if (!$theme->showInputHints) { return 0; }
    return self::layout($hints, $theme, $width)['rows'] * $theme->metrics->cellHeight;
  }

  /** @param list<ActionHint> $hints */
  public static function compose(int $width, int $height, string $id, array $hints,
    MenuPresentationCatalog $theme, CanvasRectangle $bounds): PresentationCanvas
  {
    if (!$theme->showInputHints) { return new PresentationCanvas($width, $height); }
    $layout = self::layout($hints, $theme, $bounds->width);
    $m = $theme->metrics;
    if ($layout['rows'] * $m->cellHeight > $bounds->height) {
      throw new RuntimeException('Action hints exceed their finite viewport; terminal presentation retained.');
    }
    $images = [];
    foreach ($layout['glyphs'] as $index => $glyph) {
      $box = new CanvasRectangle($bounds->x + $glyph['column'] * $m->cellWidth,
        $bounds->y + $glyph['row'] * $m->cellHeight, $glyph['cells'] * $m->cellWidth, $m->cellHeight);
      array_push($images, ...MenuIconRegistry::containAsset($theme->assetRoot, $id . '-control-' . $index,
        $glyph['asset'], $box, 30, $bounds));
    }
    $text = $layout['rows'] === 0 ? [] : [new CanvasTextLayer($id, 30, $bounds->x, $bounds->y,
      new RendererGridConfig($layout['cells'], $layout['rows'], $m->cellWidth, $m->cellHeight), $layout['runs'], $bounds)];
    return new PresentationCanvas($width, $height, $images, textLayers: $text);
  }

  /** Measurement and painting use the same already-resolved snapshot, even when profiles change.
   * @param list<ActionHint> $hints
   * @return array{cells:int, rows:int, runs:list<PresentationTextRun>, glyphs:list<array{asset:string, row:int, column:int, cells:int}>}
   */
  private static function layout(array $hints, MenuPresentationCatalog $theme, float $width): array
  {
    if (!array_is_list($hints)) { throw new InvalidArgumentException('Action hints must be an ordered list.'); }
    $cells = (int)floor($width / $theme->metrics->cellWidth);
    if ($cells < 1) { throw new RuntimeException('Action hints have no available width.'); }
    $row = $column = 0;
    $runs = $glyphs = [];
    $append = static function (string $text, bool $keycap = false) use (&$runs, &$row, &$column, $cells, $theme): void {
      while ($text !== '') {
        if ($column === $cells) { $column = 0; $row++; }
        $part = mb_substr($text, 0, $cells - $column, 'UTF-8');
        $length = mb_strlen($part, 'UTF-8');
        $runs[] = new PresentationTextRun($row, $column, $part, $theme->colors[$keycap ? 'text' : 'disabled'],
          $keycap ? $theme->colors['selected'] : null);
        $column += $length;
        $text = mb_substr($text, $length, null, 'UTF-8');
      }
    };
    foreach ($hints as $hint) {
      if (!$hint instanceof ActionHint) { throw new InvalidArgumentException('Action hints require typed descriptors.'); }
      $control = $hint->control;
      // Unknown/missing glyph roles use readable labels, not an unrelated semantic Unknown icon.
      $asset = $control === null ? null : ($theme->icons?->icons[$control->iconRole()] ?? null);
      $glyphCells = (int)ceil($theme->rows->metrics->iconWidth / $theme->metrics->cellWidth);
      if ($glyphCells > $cells) { $asset = null; }
      $label = $control === null ? 'Unbound' : ' ' . $control->label . ' ';
      $controlCells = $asset === null ? mb_strlen($label, 'UTF-8') : $glyphCells;
      $length = $controlCells + 2 + mb_strlen($hint->label, 'UTF-8');
      if ($column > 0) {
        if ($column + 2 + $length > $cells) { $column = 0; $row++; }
        else { $append('  '); }
      }
      if ($asset === null) { $append($label, $control !== null); }
      else {
        $glyphs[] = ['asset' => $asset, 'row' => $row, 'column' => $column, 'cells' => $glyphCells];
        $column += $glyphCells;
      }
      $append(': ' . $hint->label);
    }
    return ['cells' => $cells, 'rows' => $hints === [] ? 0 : $row + 1, 'runs' => $runs, 'glyphs' => $glyphs];
  }
}
