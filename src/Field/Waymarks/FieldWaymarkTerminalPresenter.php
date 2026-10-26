<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Waymarks;

use Closure;
use Ichiloto\Engine\Animations\Field\FieldCueStyle;
use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Animations\Field\FieldWaymarkStyle;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;

/**
 * Draws waymarks as terminal glyphs: the waymark symbol where its subject
 * is on screen, or an arrow at the view edge pointing toward it.
 *
 * Arrows sit on the line from the view centre toward the subject, kept the
 * style's inset from the edge. Guidance is UI: no glyph ever moves to make
 * way for the player. Where the player stands on a glyph's cell, the player
 * shows and that glyph is left out until the player moves on. The layer is
 * field guidance: over the map and every character, below UI, so dialogue
 * and menus always cover it.
 */
final class FieldWaymarkTerminalPresenter
{
  public const string LAYER = 'field-waymarks';
  /** How a cued object (an NPC showing its own cue) is named among the cued markers. */
  public const string OBJECT_KEY = 'object:';
  /** Terminal cells are about twice as tall as they are wide. */
  private const float CELL_ASPECT = 2.0;
  private ?string $signature = null;

  /**
   * Computes where each glyph goes, in screen cells.
   *
   * @param list<FieldWaymark> $waymarks
   * @param Closure(Vector2): Vector2 $toScreen Projects a world cell to a screen cell.
   * @param array<string, true> $cuedMarkers Events (by marker) and objects (by OBJECT_KEY and sprite identity) whose own cue
   *   already marks them on screen.
   * @param FieldCueStyle $cueStyle The game's cue treatment: background and bold.
   * @return list<array{x: int, y: int, text: string}>
   */
  public static function place(array $waymarks, FieldWaymarkStyle $style, Closure $toScreen, int $width, int $height,
    ?Vector2 $player = null, array $cuedMarkers = [], FieldCueStyle $cueStyle = new FieldCueStyle()): array
  {
    if ($width < 1 || $height < 1) { return []; }
    $placements = [];
    $taken = [];
    $playerX = $player === null ? null : (int)$player->x;
    $playerY = $player === null ? null : (int)$player->y;
    foreach ($waymarks as $waymark) {
      $screen = $toScreen($waymark->anchor->cell);
      $x = (int)$screen->x;
      $y = (int)$screen->y;
      if ($x >= 0 && $x < $width && $y >= 0 && $y < $height) {
        if (($waymark->anchor->eventMarker !== null && isset($cuedMarkers[$waymark->anchor->eventMarker]))
          || ($waymark->anchor->objectId !== null && isset($cuedMarkers[self::OBJECT_KEY . $waymark->anchor->objectId]))) { continue; }
        // A character occupies its cell, so its waymark floats above it.
        if ($waymark->anchor->objectId !== null && $y > 0) { $y--; }
        $text = $style->styleSymbol($cueStyle);
      } else {
        $edge = self::locateEdge($x, $y, $width, $height, $style->inset);
        [$x, $y] = [$edge['x'], $edge['y']];
        $text = $style->styleArrow($edge['direction'], $cueStyle);
      }
      // The player's own cell shows the player; the glyph keeps its cell and returns once the player moves on.
      if ($x === $playerX && $y === $playerY) { continue; }
      if (isset($taken["{$x}:{$y}"])) { continue; }
      $taken["{$x}:{$y}"] = true;
      $placements[] = ['x' => $x, 'y' => $y, 'text' => $text];
    }
    return $placements;
  }

  /**
   * Places an arrow where the ray from the view centre toward an off-screen
   * cell meets the inset view edge.
   *
   * @return array{x: int, y: int, direction: string}
   */
  public static function locateEdge(int $x, int $y, int $width, int $height, int $inset = 0): array
  {
    $centreX = ($width - 1) / 2;
    $centreY = ($height - 1) / 2;
    $dx = $x - $centreX;
    $dy = $y - $centreY;
    $index = ((int)round(atan2($dy * self::CELL_ASPECT, $dx) / (M_PI / 4)) + 8) % 8;
    $minX = min($inset, (int)floor($centreX));
    $minY = min($inset, (int)floor($centreY));
    $maxX = max($minX, $width - 1 - $inset);
    $maxY = max($minY, $height - 1 - $inset);
    $halfWidth = max(0.0, $centreX - $minX);
    $halfHeight = max(0.0, $centreY - $minY);
    $scale = min($dx == 0 ? INF : $halfWidth / abs($dx), $dy == 0 ? INF : $halfHeight / abs($dy));
    $edgeX = (int)max($minX, min($maxX, round($centreX + $dx * $scale)));
    $edgeY = (int)max($minY, min($maxY, round($centreY + $dy * $scale)));
    return ['x' => $edgeX, 'y' => $edgeY, 'direction' => FieldPresentationCatalog::DIRECTIONS[$index]];
  }

  /**
   * Whether the layer already shows exactly these placements (an empty
   * list matches a cleared layer).
   *
   * @param list<array{x: int, y: int, text: string}> $placements
   */
  public function isShowing(array $placements): bool
  {
    return $placements === [] ? $this->signature === null : serialize($placements) === $this->signature;
  }

  /**
   * Redraws the layer when the glyphs changed, or always when forced
   * (a recomposed frame rebuilds every layer).
   *
   * @param list<array{x: int, y: int, text: string}> $placements From place().
   */
  public function draw(Camera $camera, array $placements, bool $force = false): void
  {
    if ($placements === []) { $this->clear(); return; }
    $signature = serialize($placements);
    if (!$force && $signature === $this->signature) { return; }
    $this->signature = $signature;
    Console::removeLayer(self::LAYER);
    Console::withLayer(self::LAYER, function () use ($camera, $placements): void {
      foreach ($placements as $placement) {
        $camera->renderAtScreenPosition($placement['text'], new Vector2($placement['x'], $placement['y']));
      }
    }, PresentationLayerPolicy::FIELD_GUIDANCE);
  }

  public function clear(): void
  {
    if ($this->signature === null) { return; }
    $this->signature = null;
    Console::removeLayer(self::LAYER);
  }
}
