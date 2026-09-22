<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextureFallback;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\UI\Accessibility;

/** Composes owned, live battle windows. It never selects or evaluates commands. */
final class GraphicalBattleHud
{
  /** @return array<string, int> Decoded source costs, shared with arena preflight. */
  public static function preflight(BattleCanvasLayout $layout, string $assetRoot, array $battlefield = []): array
  {
    $textures = array_values([
      ...($layout->skin?->textures ?? []), ...($layout->skin?->targetCursor?->textures ?? []),
    ]);
    // Validate authored destinations independently of whether an optional file exists.
    CanvasImagePreflight::textures($textures);
    return CanvasImagePreflight::inspect([...$battlefield, ...CanvasImagePreflight::textures(
      CanvasTextureFallback::getAvailableTextures($textures, $assetRoot))], $assetRoot)['sources'];
  }

  public static function compose(BattleCanvasLayout $arena, BattleHudSnapshot $hud, ?string $focus, float $now,
    ?string $assetRoot = null): PresentationCanvas
  {
    $skin = $arena->skin;
    if ($skin === null) { return new PresentationCanvas($arena->width, $arena->height); }
    $images = $text = [];
    $pitch = $arena->uiGrid;
    $ox = ($arena->width - 135 * $pitch->cellWidth) / 2;
    $oy = ($arena->height - 36 * $pitch->cellHeight) / 2;
    $y = $oy + 30 * $pitch->cellHeight;
    $height = 6 * $pitch->cellHeight;
    foreach ([['command', $hud->commands, 0, 14], ['submenu', $hud->context, 14, 62], ['names', $hud->names, 76, 24]] as [$id, $list, $column, $columns]) {
      if ($list === null) { continue; }
      $x = $ox + $column * $pitch->cellWidth;
      $width = $columns * $pitch->cellWidth;
      self::renderImage($images, $text, $skin, 'panel', 'hud-' . $id, new CanvasRectangle($x, $y, $width, $height), 1000, $assetRoot);
      self::line($text, $id . '-title', $list->title, $x + 16, $y + 2, $width - 32, 16, $skin->colors['focus'], cellWidth: 8);
      self::line($text, $id . '-help', $list->help, $x + 16, $y + $height - 18, $width - 32, 16, $skin->colors['muted'], cellWidth: 8);
      $rowText = [];
      foreach ($list->rows as $rowIndex => $row) {
        $rowY = $y + ($rowIndex + 1) * $pitch->cellHeight;
        $color = $row->affordable ? $skin->colors['text'] : $skin->colors['disabled'];
        if ($row->selected) {
          $background = Accessibility::prefersHighContrast() ? PresentationColor::rgb(0, 0, 0) : $skin->colors['selected'];
          self::line($text, $id . '-highlight', str_repeat(' ', (int)floor(($width - 16) / $pitch->cellWidth)),
            $x + 8, $rowY, $width - 16, $pitch->cellHeight, $color, $background, $pitch->cellWidth);
          if ($focus === $id) {
            $offset = self::cursorOffset($now, Accessibility::prefersReducedMotion() || !$row->affordable);
            self::renderImage($images, $text, $skin, 'selector', 'hud-cursor', new CanvasRectangle($x + 16 + $offset,
              $rowY + ($pitch->cellHeight - 16) / 2, 16, 16), 1003, $assetRoot);
          }
          if (Accessibility::prefersHighContrast()) { $color = PresentationColor::rgb(255, 255, 255); }
        }
        self::line($rowText, $id . '-row-' . $rowIndex, $row->label, $x + 40, $rowY,
          $width - 48, $pitch->cellHeight, $color, cellWidth: $pitch->cellWidth);
      }
      self::column($text, $id . '-rows', $rowText);
      if ($list->rows === [] && $list->emptyMessage !== '') {
        self::line($text, $id . '-empty', $list->emptyMessage, $x + 16, $y + $pitch->cellHeight,
          $width - 32, $pitch->cellHeight, $skin->colors['muted'], cellWidth: $pitch->cellWidth);
      }
    }
    if ($hud->status !== null) {
      $x = $ox + 100 * $pitch->cellWidth;
      $width = 35 * $pitch->cellWidth;
      self::renderImage($images, $text, $skin, 'panel', 'hud-stats', new CanvasRectangle($x, $y, $width, $height), 1000, $assetRoot);
      $atb = array_any($hud->status->rows, static fn($row) => $row->atbRatio !== null);
      $hpWidth = $mpWidth = 4 * $pitch->cellWidth;
      foreach ($hud->status->rows as $row) {
        $hpWidth = max($hpWidth, max(strlen((string)$row->currentHp), strlen((string)$row->totalHp)) * $pitch->cellWidth);
        $mpWidth = max($mpWidth, max(strlen((string)$row->currentMp), strlen((string)$row->totalMp)) * $pitch->cellWidth);
      }
      $trackSpace = $width - 32 - $hpWidth - $mpWidth - 28 - ($atb ? 52 : 0);
      $hpTrackWidth = max(24, floor($trackSpace * 0.63));
      $mpTrackWidth = $trackSpace - $hpTrackWidth;
      if ($mpTrackWidth < 24) {
        throw new \RuntimeException('Battle resource values require a wider graphical status panel; values cannot be truncated.');
      }
      $hpTrackX = $x + 16 + $hpWidth + 8;
      $mpX = $hpTrackX + $hpTrackWidth + 12;
      $mpTrackX = $mpX + $mpWidth + 8;
      $atbX = $mpTrackX + $mpTrackWidth + 12;
      foreach ([['HP', $hpTrackX], ['MP', $mpTrackX], ...($atb ? [['ATB', $atbX]] : [])] as [$label, $labelX]) {
        self::line($text, 'stats-' . $label, $label, $labelX, $y + 2, $x + $width - 16 - $labelX, 16,
          $skin->colors['muted'], cellWidth: 8);
      }
      $hpText = $mpText = $hpUnknown = $mpUnknown = $atbFallback = [];
      foreach ($hud->status->rows as $index => $row) {
        $rowY = $y + ($index + 1) * $pitch->cellHeight;
        self::line($hpText, 'hp-' . $index, (string)$row->currentHp, $x + 16, $rowY, $hpWidth,
          $pitch->cellHeight, $skin->colors['text'], cellWidth: $pitch->cellWidth, rightAligned: true);
        self::line($mpText, 'mp-' . $index, (string)$row->currentMp, $mpX, $rowY, $mpWidth,
          $pitch->cellHeight, $skin->colors['text'], cellWidth: $pitch->cellWidth, rightAligned: true);
        $hpUnknown[] = self::renderGauge($images, $skin, 'hp', $index, $hpTrackX, $rowY + 4, $hpTrackWidth,
          $row->totalHp > 0 ? $row->hpRatio : null, $pitch->cellHeight, $assetRoot);
        $mpUnknown[] = self::renderGauge($images, $skin, 'mp', $index, $mpTrackX, $rowY + 4, $mpTrackWidth,
          $row->totalMp > 0 ? $row->mpRatio : null, $pitch->cellHeight, $assetRoot);
        if ($row->atbRatio !== null) { $atbFallback[] = self::renderGauge($images, $skin, 'atb', $index, $atbX, $rowY + 4, 40, $row->atbRatio, 20, $assetRoot); }
      }
      self::column($text, 'hp-rows', $hpText);
      self::column($text, 'mp-rows', $mpText);
      self::column($text, 'hp-unknown', $hpUnknown);
      self::column($text, 'mp-unknown', $mpUnknown);
      self::column($text, 'atb-fallback', $atbFallback);
    }
    if ($hud->message !== null) {
      $bounds = new CanvasRectangle($ox + 2 * $pitch->cellWidth, $oy + $pitch->cellHeight,
        131 * $pitch->cellWidth, min($arena->height - $oy - $pitch->cellHeight,
          (max(1, $hud->messageRows) + 2) * $pitch->cellHeight));
      self::renderImage($images, $text, $skin, 'quiet', 'hud-message', $bounds, 1000, $assetRoot);
      self::line($text, 'message', $hud->message, $bounds->x + 16, $bounds->y + $pitch->cellHeight,
        $bounds->width - 32, $pitch->cellHeight, $skin->colors['text'], cellWidth: $pitch->cellWidth,
        rows: max(1, (int)floor($bounds->height / $pitch->cellHeight) - 2), centered: true);
    }
    if (!CanvasTextureFallback::isAvailable($skin->textures['panel'], $assetRoot)
      || !CanvasTextureFallback::isAvailable($skin->textures['quiet'], $assetRoot)) {
      $text = array_map(static fn(CanvasTextLayer $layer): CanvasTextLayer => new CanvasTextLayer(
        $layer->id, $layer->layer, $layer->x, $layer->y, $layer->grid,
        array_map(static fn(PresentationTextRun $run): PresentationTextRun => new PresentationTextRun(
          $run->row, $run->column, $run->text, $run->foreground, $run->background ?? $skin->colors['ink']), $layer->runs),
        $layer->clipRect, $layer->opacity, $layer->glyphEffects), $text);
    }
    return new PresentationCanvas($arena->width, $arena->height, $images, textLayers: $text);
  }

  public static function cursorOffset(float $now, bool $reducedMotion): float
  {
    return $reducedMotion ? 0.0 : 2 * (1 - cos(2 * M_PI * fmod($now, 1.2) / 1.2));
  }

  private static function renderGauge(array &$images, BattleUiSkin $skin, string $role, int $index,
    float $x, float $y, float $width, ?float $ratio, int $rowHeight = 20, ?string $assetRoot = null): ?CanvasTextLayer
  {
    $track = $skin->textures['track']->images("{$role}-track-{$index}", new CanvasRectangle($x, $y, $width, 12), 1001);
    if (CanvasTextureFallback::isAvailable($skin->textures['track'], $assetRoot)) { array_push($images, ...$track); }
    $inner = new CanvasRectangle($x + 2, $y + 2, $width - 4, 8);
    $skin->textures[$role]->images("{$role}-fill-{$index}", $inner, 1002);
    $available = CanvasTextureFallback::isAvailable($skin->textures[$role], $assetRoot);
    if ($ratio === null || !$available) {
      $label = $ratio === null ? '-' : (string)round(max(0, min(1, $ratio)) * 100) . '%';
      $cw = max(1, min(8, (int)floor($width / 4)));
      return new CanvasTextLayer("{$role}-unknown-{$index}", 1002, $x + ($width - 4 * $cw) / 2, $y - 4,
        new RendererGridConfig(4, 1, $cw, $rowHeight), [new PresentationTextRun(0, 0, $label,
          $skin->colors['text'], $skin->colors['ink'])],
        new CanvasRectangle($x + ($width - 4 * $cw) / 2, $y - 4, 4 * $cw, $rowHeight));
    }
    $ratio = max(0, min(1, $ratio));
    if ($ratio <= 0) { return null; }
    array_push($images, ...$skin->textures[$role]->images("{$role}-fill-{$index}", $inner, 1002,
      new CanvasRectangle($inner->x, $inner->y, $inner->width * $ratio, $inner->height)));
    return null;
  }

  private static function renderImage(array &$images, array &$text, BattleUiSkin $skin, string $role, string $id,
    CanvasRectangle $bounds, int $layer, ?string $assetRoot, ?CanvasRectangle $clip = null): void
  {
    $color = match ($role) { 'hp' => 'healing', 'mp' => 'mp', 'atb', 'selector' => 'focus', 'track' => 'muted', default => 'ink' };
    if (in_array($role, ['panel', 'quiet'], true) && !CanvasTextureFallback::isAvailable($skin->textures[$role], $assetRoot)) {
      $skin->textures[$role]->images($id, $bounds, $layer, $clip);
      return;
    }
    $parts = CanvasTextureFallback::render($skin->textures[$role], $id, $bounds, $layer, $skin->colors[$color], $assetRoot, $clip);
    array_push($images, ...$parts['images']);
    array_push($text, ...$parts['textLayers']);
  }

  private static function line(array &$layers, string $id, string $label, float $x, float $y, float $width, int $height,
    PresentationColor $color, ?PresentationColor $background = null, int $cellWidth = 10,
    int $rows = 1, bool $centered = false, bool $rightAligned = false): void
  {
    if ($label === '') { return; }
    $columns = max(1, (int)floor($width / $cellWidth));
    $runs = [];
    // Like the owned Window, project only its viewport; the snapshot keeps the complete source.
    foreach (array_slice(explode("\n", TerminalText::stripAnsi($label)), 0, $rows) as $row => $line) {
      $line = mb_substr($line, 0, $columns, 'UTF-8');
      $space = $columns - mb_strlen($line, 'UTF-8');
      $column = $rightAligned ? $space : ($centered ? intdiv($space, 2) : 0);
      $runs[] = new PresentationTextRun($row, $column, $line, $color, $background);
    }
    $layers[] = new CanvasTextLayer('hud-' . $id, 1002, $x, $y, new RendererGridConfig($columns, $rows, $cellWidth, $height),
      $runs, new CanvasRectangle($x, $y, $width, $height * $rows));
  }

  /** Pack one aligned HUD column without consuming a canvas layer per party member. */
  private static function column(array &$layers, string $id, array $lines): void
  {
    $lines = array_values(array_filter($lines));
    if ($lines === []) { return; }
    $first = $lines[0];
    $last = $lines[array_key_last($lines)];
    $rowHeight = $first->grid->cellHeight;
    $rows = (int)round(($last->y - $first->y) / $rowHeight) + 1;
    $runs = [];
    foreach ($lines as $line) {
      $row = (int)round(($line->y - $first->y) / $rowHeight);
      foreach ($line->runs as $run) {
        $runs[] = new PresentationTextRun($row + $run->row, $run->column, $run->text, $run->foreground, $run->background);
      }
    }
    $layers[] = new CanvasTextLayer('hud-' . $id, $first->layer, $first->x, $first->y,
      new RendererGridConfig($first->grid->columns, $rows, $first->grid->cellWidth, $rowHeight), $runs,
      new CanvasRectangle($first->clipRect->x, $first->clipRect->y, $first->clipRect->width,
        ($rows - 1) * $rowHeight + $last->clipRect->height));
  }
}
