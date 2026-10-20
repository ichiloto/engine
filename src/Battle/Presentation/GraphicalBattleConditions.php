<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextBatch;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\UI\Presentation\MenuIconRegistry;

/** Static semantic badges share live combat identity, not optional effect playback. */
final class GraphicalBattleConditions
{
  // Seven distinct pictograms: sword, shield, wand, ward, boot, sparkle, eye.
  private const array SYMBOLS = [
    'stat:attack' => ['.....##', '....###', '...###.', '#.###..', '.###...', '###.#..', '#....#.'],
    'stat:defence' => ['#######', '#.....#', '#..#..#', '#..#..#', '.#.#.#.', '..###..', '...#...'],
    'stat:magicAttack' => ['....#..', '...###.', '..#####', '...###.', '..#.#..', '.#.....', '#......'],
    'stat:magicDefence' => ['..###..', '.#...#.', '#..#..#', '#.###.#', '#..#..#', '.#...#.', '..###..'],
    'stat:speed' => ['..###..', '..#.#..', '..#.#..', '..#.#..', '..#.###', '..#...#', '..#####'],
    'stat:grace' => ['...#...', '...#...', '..###..', '#######', '..###..', '...#...', '...#...'],
    'stat:evasion' => ['.......', '..###..', '.#...#.', '#..#..#', '.#...#.', '..###..', '.......'],
    'state:poison' => ['..###..', '.#####.', '#######', '#.#.#.#', '#######', '..#.#..', '..###..'],
    'state:stun' => ['...#...', '#..#..#', '.#####.', '..###..', '.#####.', '#..#..#', '...#...'],
    'condition' => ['...#...', '..#.#..', '.#...#.', '#..#..#', '.#...#.', '..#.#..', '...#...'],
  ];
  private const array MARKERS = [
    'positive' => ['.#.', '###', '.#.', '.#.', '.#.'],
    'negative' => ['.#.', '.#.', '.#.', '###', '.#.'],
    'neutral' => ['...', '###', '...', '###', '...'],
  ];

  /** @param array<int, CharacterInterface> $battlers Only currently presented battlers.
   * @param array<int, CanvasRectangle> $bounds
   */
  public static function compose(BattleCanvasLayout $layout, array $battlers, array $bounds, string $assetRoot,
    ?BattleHeadAttachmentLayout $attachments = null): PresentationCanvas
  {
    $style = $layout->skin?->conditionBadges ?? new BattleConditionBadgeStyle();
    $size = $style->size;
    $gridStep = self::getGridStep($style);
    $bounds = array_intersect_key($bounds, $battlers);
    $placements = ($attachments ?? new BattleHeadAttachmentLayout())->place($battlers, $bounds,
      $layout->width, $layout->height, $style, $layout->skin?->targetCursor);
    $images = $text = $protected = [];
    foreach ($battlers as $identity => $battler) {
      if (!$battler->isFighting || !isset($bounds[$identity])) { continue; }
      $entries = $placements[$identity] ?? [];
      if ($entries === []) { continue; }
      $badgeFrames = $badgeMarkers = $badgeLabels = [];
      $batch = 0;
      foreach ($entries as $placement) {
        $entry = $placement['entry'];
        $box = $placement['bounds'];
        // The key, not list position, keeps retained identity stable after another condition is cured.
        $id = 'status-badge-' . $identity . '-' . hash('xxh3', $entry['key']);
        $asset = $layout->skin?->icons?->icons[$entry['iconKey']] ?? null;
        $iconBox = new CanvasRectangle($box->x, $box->y, $size, $size);
        $art = $asset === null ? [] : MenuIconRegistry::containAsset($assetRoot, $id . '-art', $asset, $iconBox, 231, $iconBox);
        array_push($images, ...$art);
        if ($art === [] && $badgeFrames !== [] && (max($box->y + $size, ...array_map(static fn($frame) => $frame->y + $size, $badgeFrames))
            - min($box->y, ...array_column($badgeFrames, 'y')) > RendererGridConfig::MAX_ROWS * $gridStep
          || max($box->x + $size, ...array_map(static fn($frame) => $frame->x + $size, $badgeFrames))
            - min($box->x, ...array_column($badgeFrames, 'x')) > RendererGridConfig::MAX_COLUMNS * $gridStep)) {
          $text[] = self::mergeBadgeFrames('status-badges-' . $identity . '-batch-' . $batch, $badgeFrames, $gridStep);
          $text[] = self::mergeBadgeFrames('status-markers-' . $identity . '-batch-' . $batch++, $badgeMarkers, $gridStep);
          $badgeFrames = $badgeMarkers = [];
        }
        if ($art === []) {
          $badgeFrames[] = self::renderBadge($id, $entry, $box, $style, $layout->skin);
          $badgeMarkers[] = self::renderMarker($id . '-marker', $entry, $box, $style, $layout->skin);
        }
        // A bound semantic badge owns its frame and polarity. Footers sit below the full-size
        // icon, never masking the symbol or its authored arrow; unbound states retain an identity label.
        $label = $entry['magnitude'] === null ? ($art === [] ? $entry['label'] : '')
          : ($entry['polarity'] === 'positive' ? '+' : '-') . $entry['magnitude'];
        if ($label !== '') {
          $length = mb_strlen($label, 'UTF-8');
          $badgeLabels[] = new CanvasTextLayer($id . '-label', 233,
            $box->x + ($size - $length * $style->labelCellWidth) / 2,
            $box->y + $size,
            new RendererGridConfig($length, 1, $style->labelCellWidth, $style->labelCellHeight),
            [new PresentationTextRun(0, 0, $label,
              $layout->skin?->colors['text'] ?? PresentationColor::rgb(245, 245, 245),
              $layout->skin?->colors['ink'] ?? PresentationColor::rgb(15, 23, 30))], $box);
        }
        $protected[] = $box;
      }
      if ($badgeFrames !== []) {
        $text[] = self::mergeBadgeFrames('status-badges-' . $identity . '-batch-' . $batch, $badgeFrames, $gridStep);
        $text[] = self::mergeBadgeFrames('status-markers-' . $identity . '-batch-' . $batch, $badgeMarkers, $gridStep);
      }
      array_push($text, ...self::mergeBadgeLabels($badgeLabels));
    }
    return new PresentationCanvas($layout->width, $layout->height, $images,
      textLayers: CanvasTextBatch::compact($text), protectedAreas: $protected);
  }

  private static function getGridStep(BattleConditionBadgeStyle $style): int
  {
    $step = $style->pixelSize;
    foreach ([$style->gap, $style->markerPixelSize] as $gap) {
      while ($gap !== 0) { [$step, $gap] = [$gap, $step % $gap]; }
    }
    return $step;
  }

  /** Batch primitive badge pixels without changing their painted footprint. */
  private static function mergeBadgeFrames(string $id, array $frames, int $step): CanvasTextLayer
  {
    $first = $frames[0];
    $originX = min(array_column($frames, 'x'));
    $originY = min(array_column($frames, 'y'));
    $right = $bottom = 0;
    foreach ($frames as $frame) {
      $right = max($right, $frame->bounds->x + $frame->bounds->width);
      $bottom = max($bottom, $frame->bounds->y + $frame->bounds->height);
    }
    $width = (int)round(($right - $originX) / $step);
    $height = (int)round(($bottom - $originY) / $step);
    $runs = [];
    foreach ($frames as $frame) {
      $offset = (int)round(($frame->x - $originX) / $step);
      $offsetY = (int)round(($frame->y - $originY) / $step);
      $scale = intdiv($frame->grid->cellWidth, $step);
      foreach ($frame->runs as $run) {
        for ($row = 0; $row < $scale; $row++) {
          $runs[] = new PresentationTextRun($offsetY + $run->row * $scale + $row, $offset + $run->column * $scale,
            str_repeat(' ', strlen($run->text) * $scale), background: $run->background);
        }
      }
    }
    return new CanvasTextLayer($id, $first->layer, $originX, $originY,
      new RendererGridConfig($width, $height, $step, $step), $runs);
  }

  /** Each battler's nonoverlapping footers can share font lattices even in a crowded formation. */
  private static function mergeBadgeLabels(array $labels): array
  {
    if ($labels === []) { return []; }
    $groups = [];
    $originX = min(array_column($labels, 'x'));
    $originY = min(array_column($labels, 'y'));
    foreach ($labels as $label) {
      $key = ((int)round($label->x - $originX) % $label->grid->cellWidth) . ':'
        . ((int)round($label->y - $originY) % $label->grid->cellHeight);
      $groups[$key][] = $label;
    }
    $merged = [];
    foreach ($groups as $group) {
      $x = min(array_column($group, 'x'));
      $y = min(array_column($group, 'y'));
      $first = $group[0];
      $runs = [];
      $columns = $rows = 1;
      foreach ($group as $label) {
        $column = (int)round(($label->x - $x) / $first->grid->cellWidth);
        $row = (int)round(($label->y - $y) / $first->grid->cellHeight);
        foreach ($label->runs as $run) {
          $runs[] = new PresentationTextRun($row, $column, $run->text, $run->foreground, $run->background);
          $columns = max($columns, $column + mb_strlen($run->text, 'UTF-8'));
          $rows = max($rows, $row + 1);
        }
      }
      $merged[] = new CanvasTextLayer($first->id, $first->layer, $x, $y,
        new RendererGridConfig($columns, $rows, $first->grid->cellWidth, $first->grid->cellHeight), $runs);
    }
    return $merged;
  }

  private static function renderBadge(string $id, array $entry, CanvasRectangle $box, BattleConditionBadgeStyle $style,
    ?BattleUiSkin $skin): CanvasTextLayer
  {
    $accent = self::getAccent($entry['polarity'], $skin);
    $colors = ['b' => $skin?->colors['ink'] ?? PresentationColor::rgb(15, 23, 30),
      'f' => $accent, 'a' => $accent, 't' => $skin?->colors['text'] ?? PresentationColor::rgb(245, 245, 245)];
    $pixels = array_fill(0, 16, str_repeat('b', 16));
    foreach ($pixels as $row => &$line) {
      if ($row === 0 || $row === 15) { $line = str_repeat('f', 16); }
      else { $line[0] = $line[15] = 'f'; }
    }
    unset($line);
    self::paintSymbol($pixels, self::SYMBOLS[$entry['symbol']] ?? self::SYMBOLS['condition'], 1, 1, 't', 2);
    $runs = [];
    // Sparse colored background spans are existing canvas primitives, not font-dependent artwork or a raster cache.
    foreach ($pixels as $row => $line) {
      for ($column = 0; $column < 16; $column += $span) {
        $kind = $line[$column];
        $span = strspn($line, $kind, $column);
        $runs[] = new PresentationTextRun($row, $column, str_repeat(' ', $span), background: $colors[$kind]);
      }
    }
    return new CanvasTextLayer($id, 230, $box->x, $box->y,
      new RendererGridConfig(16, 16, $style->pixelSize, $style->pixelSize), $runs, $box);
  }

  private static function renderMarker(string $id, array $entry, CanvasRectangle $box,
    BattleConditionBadgeStyle $style, ?BattleUiSkin $skin): CanvasTextLayer
  {
    $pixels = array_fill(0, 7, 'bbbbb');
    self::paintSymbol($pixels, self::MARKERS[$entry['polarity']], 1, 1, 'a');
    $accent = self::getAccent($entry['polarity'], $skin);
    $ink = $skin?->colors['ink'] ?? PresentationColor::rgb(15, 23, 30);
    $runs = [];
    foreach ($pixels as $row => $line) {
      for ($column = 0; $column < 5; $column += $span) {
        $kind = $line[$column];
        $span = strspn($line, $kind, $column);
        $runs[] = new PresentationTextRun($row, $column, str_repeat(' ', $span),
          background: $kind === 'a' ? $accent : $ink);
      }
    }
    return new CanvasTextLayer($id, 232, $box->x + $box->width - $style->pixelSize - 5 * $style->markerPixelSize,
      $box->y + $style->pixelSize, new RendererGridConfig(5, 7, $style->markerPixelSize, $style->markerPixelSize), $runs, $box);
  }

  private static function getAccent(string $polarity, ?BattleUiSkin $skin): PresentationColor
  {
    return match ($polarity) {
      'positive' => $skin?->colors['healing'] ?? PresentationColor::rgb(96, 232, 164),
      'negative' => $skin?->colors['damage'] ?? PresentationColor::rgb(255, 139, 112),
      default => $skin?->colors['focus'] ?? PresentationColor::rgb(245, 214, 115),
    };
  }

  private static function paintSymbol(array &$pixels, array $symbol, int $x, int $y, string $color, int $scale = 1): void
  {
    foreach ($symbol as $row => $line) {
      for ($column = 0; $column < strlen($line); $column++) {
        if ($line[$column] !== '#') { continue; }
        for ($dy = 0; $dy < $scale; $dy++) {
          for ($dx = 0; $dx < $scale; $dx++) { $pixels[$y + $row * $scale + $dy][$x + $column * $scale + $dx] = $color; }
        }
      }
    }
  }
}
