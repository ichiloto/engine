<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\ControlHint;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use RuntimeException;

/**
 * The one graphical owner of how a control looks. Every hint strip, lookup and footer draws its
 * controls here, so a theme changes them all in one place.
 *
 * A control shows its family's glyph image when the theme's control set has one, otherwise a keycap:
 * the set's key frame (or a rounded, bevelled key drawn in its colours) one text cell tall with the
 * control's name centred on it. An action without a control reads "Unbound", plainly, because it is
 * no key. Text on one cell lattice shares a layer, and nearby keys share their shape layers, so a
 * strip of hints costs a layer or two however many keys it shows.
 */
final class ControlGlyphPresentation
{
  /** Cells of face on each side of a keycap's name. */
  private const int KEYCAP_PADDING = 1;
  private const string UNBOUND = 'Unbound';

  /** @var list<CanvasImage> */
  private array $images = [];
  /** @var list<array{CanvasRectangle, ControlIconSet}> Keys the Engine draws because their set has no frame artwork. */
  private array $keycaps = [];
  /** @var list<array{text: string, x: float, y: float, color: PresentationColor}> */
  private array $texts = [];
  /** @var list<CanvasRectangle> */
  private array $protectedAreas = [];
  private readonly int $cellWidth;
  private readonly int $cellHeight;

  /**
   * @param string $id Names the shared layers this instance composes.
   * @param array{int, int}|null $cell A text cell (width, height) for a screen that sizes its controls; the theme's otherwise.
   */
  public function __construct(private readonly MenuPresentationCatalog $theme, private readonly string $id,
    ?array $cell = null, private readonly int $layer = 30)
  {
    [$this->cellWidth, $this->cellHeight] = $cell ?? [$theme->metrics->cellWidth, $theme->metrics->cellHeight];
  }

  /** Cells a control takes on a line of text: its glyph's width, or a keycap around its name. */
  public function measureCells(?ControlHint $control): int
  {
    if ($control === null) { return mb_strlen(self::UNBOUND, 'UTF-8'); }
    return $this->findGlyph($control) !== null ? (int)ceil($this->theme->rows->metrics->iconWidth / $this->cellWidth)
      : TerminalText::displayWidth($control->label) + 2 * self::KEYCAP_PADDING;
  }

  /**
   * Draws a control one text cell tall, centred vertically in its bounds and placed across them by
   * alignment, so it shares a centre line with any label centred in the same bounds.
   * Returns the box it occupies, so an owner can lay further content beside it.
   */
  public function renderControl(string $id, ?ControlHint $control, CanvasRectangle $bounds,
    HorizontalAlignment $alignment = HorizontalAlignment::CENTER): CanvasRectangle
  {
    $width = $this->measureCells($control) * $this->cellWidth;
    if ($width > $bounds->width + 0.001 || $this->cellHeight > $bounds->height + 0.001) {
      throw new RuntimeException('A control exceeds its finite viewport; terminal presentation retained.');
    }
    $x = match ($alignment) {
      HorizontalAlignment::LEFT => $bounds->x,
      HorizontalAlignment::CENTER => $bounds->x + ($bounds->width - $width) / 2,
      HorizontalAlignment::RIGHT => $bounds->x + $bounds->width - $width,
    };
    $box = new CanvasRectangle($x, $bounds->y + ($bounds->height - $this->cellHeight) / 2, $width, $this->cellHeight);
    $this->protectedAreas[] = $box;
    if ($control === null) {
      $this->renderText(self::UNBOUND, $box->x, $box->y, $this->theme->colors['disabled']);
      return $box;
    }
    $glyph = $this->findGlyph($control);
    if ($glyph !== null) {
      array_push($this->images, ...MenuIconRegistry::containAsset($this->theme->assetRoot, $id, $glyph, $box, $this->layer, $box));
      return $box;
    }
    $set = $this->theme->getControlSet($control->family);
    $frame = $set->keycap?->images($this->theme->assetRoot, $id, $box, $this->layer) ?? [];
    if ($frame !== []) { array_push($this->images, ...$frame); }
    else { $this->keycaps[] = [$box, $set]; }
    $this->renderText($control->label, $box->x + self::KEYCAP_PADDING * $this->cellWidth, $box->y,
      $this->theme->colors[$set->colors['label']]);
    return $box;
  }

  /**
   * One line of text beside the controls, such as a hint's action label, sharing their text layer
   * when it stands on the same cell lattice. Its top-left is in canvas pixels.
   */
  public function renderText(string $text, float $x, float $y, PresentationColor $color): void
  {
    if ($text !== '') { $this->texts[] = ['text' => $text, 'x' => $x, 'y' => $y, 'color' => $color]; }
  }

  /** Everything drawn so far: glyph and frame images, key shapes beneath their names, and protected boxes. */
  public function compose(int $width, int $height): PresentationCanvas
  {
    return new PresentationCanvas($width, $height, $this->images,
      textLayers: [...$this->composeKeycaps(), ...$this->composeTexts()], protectedAreas: $this->protectedAreas);
  }

  /** A family's whole-glyph image for a control, when the theme supplies one that loads. */
  private function findGlyph(ControlHint $control): ?string
  {
    $asset = $this->theme->getControlSet($control->family)->glyphs[$control->control] ?? null;
    return $asset !== null && PngAssetPreflight::getAvailableSize($this->theme->assetRoot, $asset) !== null ? $asset : null;
  }

  /**
   * Engine-drawn keys as sparse pixel runs: a rounded edge, a highlight along the top, the face, and a
   * darker lip along the bottom. Every key's runs go into as few pixel layers as the grid limits allow.
   *
   * @return list<CanvasTextLayer>
   */
  private function composeKeycaps(): array
  {
    $runs = [];
    foreach ($this->keycaps as [$box, $set]) {
      array_push($runs, ...$this->drawKeycap($set, (int)round($box->x), (int)round($box->y),
        (int)round($box->width), (int)round($box->height)));
    }
    if ($runs === []) { return []; }
    $left = min(array_column($runs, 'x'));
    $top = min(array_column($runs, 'y'));
    $right = max(array_map(static fn(array $run): int => $run['x'] + $run['width'], $runs));
    $bottom = max(array_column($runs, 'y')) + 1;
    $tiles = [];
    foreach ($runs as $run) {
      $tileY = intdiv($run['y'] - $top, RendererGridConfig::MAX_ROWS);
      // A run crossing a tile's right side continues in the next tile.
      for ($x = $run['x'], $end = $run['x'] + $run['width']; $x < $end; ) {
        $tileX = intdiv($x - $left, RendererGridConfig::MAX_COLUMNS);
        $tileRight = $left + ($tileX + 1) * RendererGridConfig::MAX_COLUMNS;
        $length = min($end, $tileRight) - $x;
        $tiles["{$tileY}:{$tileX}"][] = [$run['y'], $x, $length, $run['color']];
        $x += $length;
      }
    }
    $layers = [];
    foreach ($tiles as $key => $parts) {
      [$tileY, $tileX] = array_map(intval(...), explode(':', $key));
      $originX = $left + $tileX * RendererGridConfig::MAX_COLUMNS;
      $originY = $top + $tileY * RendererGridConfig::MAX_ROWS;
      $columns = min(RendererGridConfig::MAX_COLUMNS, $right - $originX);
      $rows = min(RendererGridConfig::MAX_ROWS, $bottom - $originY);
      $layers[] = new CanvasTextLayer($this->id . '-keycaps' . ($layers === [] ? '' : '-' . count($layers)), $this->layer,
        $originX, $originY, new RendererGridConfig($columns, $rows, 1, 1), array_map(
          static fn(array $part): PresentationTextRun => new PresentationTextRun($part[0] - $originY, $part[1] - $originX,
            str_repeat(' ', $part[2]), background: $part[3]), $parts));
    }
    return $layers;
  }

  /** @return list<array{y: int, x: int, width: int, color: PresentationColor}> One key's pixel rows in canvas pixels. */
  private function drawKeycap(ControlIconSet $set, int $left, int $top, int $width, int $height): array
  {
    [$face, $edge, $highlight, $lip] = array_map(fn(string $part) => $this->theme->colors[$set->colors[$part]],
      ['face', 'edge', 'highlight', 'lip']);
    $lipRows = min($set->lip, max(0, $height - 5));
    $runs = [];
    for ($row = 0; $row < $height; $row++) {
      // Corners round off by two pixels on the outer rows and one on the next.
      $inset = $row === 0 || $row === $height - 1 ? 2 : ($row === 1 || $row === $height - 2 ? 1 : 0);
      $span = $width - 2 * $inset;
      if ($span < 1) { continue; }
      $y = $top + $row;
      if ($row === 0 || $row === $height - 1 || $span < 3) {
        $runs[] = ['y' => $y, 'x' => $left + $inset, 'width' => $span, 'color' => $edge];
        continue;
      }
      $fill = $row === 1 ? $highlight : ($row >= $height - 1 - $lipRows ? $lip : $face);
      $runs[] = ['y' => $y, 'x' => $left + $inset, 'width' => 1, 'color' => $edge];
      $runs[] = ['y' => $y, 'x' => $left + $inset + 1, 'width' => $span - 2, 'color' => $fill];
      $runs[] = ['y' => $y, 'x' => $left + $inset + $span - 1, 'width' => 1, 'color' => $edge];
    }
    return $runs;
  }

  /**
   * Text on one cell lattice shares a layer, one grid row per text cell row.
   *
   * @return list<CanvasTextLayer>
   */
  private function composeTexts(): array
  {
    $groups = [];
    foreach ($this->texts as $text) {
      $key = round(fmod(fmod($text['x'], $this->cellWidth) + $this->cellWidth, $this->cellWidth), 2) . ':'
        . round(fmod(fmod($text['y'], $this->cellHeight) + $this->cellHeight, $this->cellHeight), 2);
      $groups[$key][] = $text;
    }
    $layers = [];
    foreach (array_values($groups) as $index => $texts) {
      $originX = min(array_column($texts, 'x'));
      $originY = min(array_column($texts, 'y'));
      $columns = $rows = 1;
      $runs = [];
      foreach ($texts as $text) {
        $column = (int)round(($text['x'] - $originX) / $this->cellWidth);
        $row = (int)round(($text['y'] - $originY) / $this->cellHeight);
        $runs[] = new PresentationTextRun($row, $column, $text['text'], $text['color']);
        $columns = max($columns, $column + TerminalText::displayWidth($text['text']));
        $rows = max($rows, $row + 1);
      }
      usort($runs, static fn(PresentationTextRun $a, PresentationTextRun $b): int => [$a->row, $a->column] <=> [$b->row, $b->column]);
      $layers[] = new CanvasTextLayer($this->id . ($index === 0 ? '' : '-text-' . $index), $this->layer + 1,
        $originX, $originY, new RendererGridConfig($columns, $rows, $this->cellWidth, $this->cellHeight), $runs);
    }
    return $layers;
  }
}
