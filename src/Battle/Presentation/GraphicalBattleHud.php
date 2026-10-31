<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Battle\BattleCommandType;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Localization\Vocabulary;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextureFallback;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationFallbackReport;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\UI\Accessibility;
use Ichiloto\Engine\UI\Presentation\ControlGlyphPresentation;
use Ichiloto\Engine\UI\Presentation\MenuActionHints;
use Ichiloto\Engine\UI\Presentation\MenuIconRegistry;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\TerminalControlText;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use RuntimeException;

/** Composes owned, live battle windows. It never selects or evaluates commands. */
final class GraphicalBattleHud
{
  /** @return array<string, int> Decoded source costs, shared with arena preflight. */
  public static function preflight(BattleCanvasLayout $layout, string $assetRoot, array $battlefield = []): array
  {
    $textures = array_values([
      ...($layout->skin?->textures ?? []), ...($layout->skin?->targetCursor?->textures ?? []),
    ]);
    if ($layout->skin !== null) {
      foreach (self::getCommandIcons($layout->skin, $assetRoot) as $asset) {
        $textures[] = CanvasNineSlice::getFromPng($assetRoot, $asset);
      }
    }
    // Validate authored destinations independently of whether an optional file exists.
    CanvasImagePreflight::textures($textures);
    return CanvasImagePreflight::inspect([...$battlefield, ...CanvasImagePreflight::textures(
      CanvasTextureFallback::getAvailableTextures($textures, $assetRoot))], $assetRoot)['sources'];
  }

  /**
   * @param MenuPresentationCatalog|null $controls The menu theme, whose control sets draw each panel's hints as keycaps;
   *   without it a panel's help reads as the Terminal's text, and that fallback is reported.
   * @param PresentationFallbackReport|null $fallbacks Where the owner logs a fallback; without one each is logged directly.
   */
  public static function compose(BattleCanvasLayout $arena, BattleHudSnapshot $hud, ?string $focus, float $now,
    ?string $assetRoot = null, ?MenuPresentationCatalog $controls = null,
    ?PresentationFallbackReport $fallbacks = null): PresentationCanvas
  {
    $fallbacks ??= new PresentationFallbackReport();
    $skin = $arena->skin;
    if ($skin === null) { return new PresentationCanvas($arena->width, $arena->height); }
    $images = $text = [];
    $iconRoot = $assetRoot ?? $skin->icons?->assetRoot;
    $icons = $iconRoot === null ? [] : self::getCommandIcons($skin, $iconRoot);
    $pitch = $arena->uiGrid;
    $ox = ($arena->width - 135 * $pitch->cellWidth) / 2;
    $oy = ($arena->height - 36 * $pitch->cellHeight) / 2;
    $y = $arena->getHudTop();
    $height = BattleCanvasLayout::HUD_ROWS * $pitch->cellHeight;
    foreach ([['command', $hud->commands, 0, 14], ['submenu', $hud->context, 14, 62], ['names', $hud->names, 76, 24]] as [$id, $list, $column, $columns]) {
      if ($list === null) { continue; }
      $x = $ox + $column * $pitch->cellWidth;
      $width = $columns * $pitch->cellWidth;
      self::renderImage($images, $text, $skin, 'panel', 'hud-' . $id, new CanvasRectangle($x, $y, $width, $height), 1000, $assetRoot);
      self::line($text, $id . '-title', $list->title, $x + 16, $y + 2, $width - 32, 16, $skin->colors['focus'], cellWidth: 8);
      self::renderHelp($images, $text, $id, $list, new CanvasRectangle($x + 16, $y + $height - 18, $width - 32, 16),
        $skin, $arena, $controls, $fallbacks);
      $rowText = [];
      // Keep one aligned text column even when some optional icons are unavailable.
      $iconSpace = $id === 'submenu' && array_any($list->rows,
        static fn(BattleHudRow $row): bool => isset($icons[$row->iconRole ?? ''])) ? 24 : 0;
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
        if ($iconSpace > 0 && isset($icons[$row->iconRole ?? '']) && $iconRoot !== null) {
          array_push($images, ...MenuIconRegistry::containAsset($iconRoot, 'hud-submenu-icon-' . $row->index,
            $icons[$row->iconRole], new CanvasRectangle($x + 40, $rowY + ($pitch->cellHeight - 16) / 2, 16, 16),
            1002, new CanvasRectangle($x + 40, $rowY, 16, $pitch->cellHeight)));
        }
        self::line($rowText, $id . '-row-' . $rowIndex, $row->label, $x + 40 + $iconSpace, $rowY,
          $width - 48 - $iconSpace, $pitch->cellHeight, $color, cellWidth: $pitch->cellWidth);
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
      foreach ([['HP', 'stats.hp', $hpTrackX], ['MP', 'stats.mp', $mpTrackX],
        ...($atb ? [['Time', 'battle.atb', $atbX]] : [])] as [$id, $term, $labelX]) {
        self::line($text, 'stats-' . $id, Vocabulary::getTerm($term, $id), $labelX, $y + 2, $x + $width - 16 - $labelX, 16,
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
    // The battle's messages, then its result, read in the band above the battlefield, clear of every battler.
    $bandTop = $oy + $pitch->cellHeight;
    if ($hud->message !== null) {
      $bounds = new CanvasRectangle($ox + 2 * $pitch->cellWidth, $bandTop,
        131 * $pitch->cellWidth, min($arena->height - $oy - $pitch->cellHeight,
          (max(1, $hud->messageRows) + 2) * $pitch->cellHeight));
      self::renderImage($images, $text, $skin, 'quiet', 'hud-message', $bounds, 1000, $assetRoot);
      self::line($text, 'message', $hud->message, $bounds->x + 16, $bounds->y + $pitch->cellHeight,
        $bounds->width - 32, $pitch->cellHeight, $skin->colors['text'], cellWidth: $pitch->cellWidth,
        rows: max(1, (int)floor($bounds->height / $pitch->cellHeight) - 2), centered: true);
      $bandTop = $bounds->y + $bounds->height + $pitch->cellHeight / 2;
    }
    if ($hud->outcome !== null) {
      self::renderOutcome($images, $text, $hud->outcome, $ox + 2 * $pitch->cellWidth, $bandTop,
        131 * $pitch->cellWidth, $skin, $arena, $assetRoot, $controls, $fallbacks);
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

  /** Missing command artwork falls back to its plain label, not an unrelated unknown icon.
   * @return array<string, string>
   */
  private static function getCommandIcons(BattleUiSkin $skin, string $root): array
  {
    $icons = [];
    foreach (BattleCommandType::cases() as $type) {
      $role = $type->getIconRole();
      $asset = $skin->icons?->icons[$role] ?? null;
      if ($asset !== null && PngAssetPreflight::getAvailableSize($root, $asset) !== null) {
        $icons[$role] = $asset;
      }
    }
    return $icons;
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

  /**
   * The battle's result in a panel centred in the band above the battlefield, as wide as its content: its title,
   * the page the result window shows, and each control that acts on it as its key alone, at the panel's foot.
   * The window keeps the result, its reveal, its pages and its input. A result that cannot fit is an error,
   * never truncated.
   */
  private static function renderOutcome(array &$images, array &$text, BattleOutcomeSnapshot $outcome,
    float $x, float $y, float $width, BattleUiSkin $skin, BattleCanvasLayout $arena, ?string $assetRoot,
    ?MenuPresentationCatalog $controls, PresentationFallbackReport $fallbacks): void
  {
    $pitch = $arena->uiGrid;
    // Clear of the panel frame's corners.
    $padding = 24;
    $keys = [];
    foreach ($outcome->hints as $hint) {
      array_push($keys, $hint->control, ...($hint->pairedAction === null ? [] : [$hint->pairedControl]));
    }
    $glyphs = $controls === null ? null
      : new ControlGlyphPresentation($controls, 'hud-outcome-keys', [$pitch->cellWidth, $pitch->cellHeight], 1002);
    $keyText = TerminalControlText::formatControls($keys);
    $keyCells = $keys === [] ? 0 : ($glyphs === null ? TerminalText::displayWidth($keyText)
      : array_sum(array_map($glyphs->measureCells(...), $keys)) + count($keys) - 1);
    $page = $outcome->pageCount > 1 ? ($outcome->pageIndex + 1) . ' / ' . $outcome->pageCount : '';
    // A centred page count keeps clear of the keys on both sides.
    $footerCells = $page === '' ? $keyCells : mb_strlen($page) + 2 * ($keyCells + 1);
    $contentCells = max($footerCells, ...array_map(static fn(string $line): int =>
      TerminalText::displayWidth(TerminalText::stripAnsi($line)), [$outcome->title, ...$outcome->lines]));
    $available = (int)floor(($width - 2 * $padding) / $pitch->cellWidth);
    if ($contentCells > $available) {
      throw new RuntimeException('A battle result line exceeds its panel; its window must wrap it.');
    }
    $columns = min($available, max(36, $contentCells + 4));
    // The title, then the page, then the controls, each set apart by half a row.
    $gap = $pitch->cellHeight / 2;
    $lineRows = max(1, count($outcome->lines));
    $hasFooter = $keyCells + mb_strlen($page) > 0;
    $bounds = new CanvasRectangle($x + ($width - $columns * $pitch->cellWidth - 2 * $padding) / 2, $y,
      $columns * $pitch->cellWidth + 2 * $padding,
      2 * $padding + (1 + $lineRows) * $pitch->cellHeight + $gap + ($hasFooter ? $gap + $pitch->cellHeight : 0));
    if ($bounds->y + $bounds->height > $arena->height) {
      throw new RuntimeException('The battle result exceeds the battle canvas; its window must paginate it.');
    }
    $inner = new CanvasRectangle($bounds->x + $padding, $bounds->y + $padding, $columns * $pitch->cellWidth,
      $bounds->height - 2 * $padding);
    self::renderImage($images, $text, $skin, 'panel', 'hud-outcome', $bounds, 1000, $assetRoot);
    self::line($text, 'outcome-title', $outcome->title, $inner->x, $inner->y, $inner->width, $pitch->cellHeight,
      $skin->colors['focus'], cellWidth: $pitch->cellWidth, centered: true);
    self::line($text, 'outcome-lines', implode("\n", $outcome->lines), $inner->x, $inner->y + $pitch->cellHeight + $gap,
      $inner->width, $pitch->cellHeight, $skin->colors['text'], cellWidth: $pitch->cellWidth,
      rows: $lineRows, centered: true);
    $footer = new CanvasRectangle($inner->x, $inner->y + $inner->height - $pitch->cellHeight, $inner->width, $pitch->cellHeight);
    if ($page !== '') {
      self::line($text, 'outcome-page', $page, $footer->x, $footer->y, $footer->width, $pitch->cellHeight,
        $skin->colors['muted'], cellWidth: $pitch->cellWidth, centered: true);
    }
    if ($keys === []) { return; }
    if ($glyphs === null) {
      $fallbacks->report('Battle result controls read as text: the project has no menu theme to draw keycaps.');
      self::line($text, 'outcome-keys', $keyText, $footer->x, $footer->y, $footer->width, $pitch->cellHeight,
        $skin->colors['muted'], cellWidth: $pitch->cellWidth, rightAligned: true);
      return;
    }
    // Each control alone, right to left from the panel's inner edge.
    $right = $footer->x + $footer->width;
    foreach (array_reverse($keys, true) as $index => $control) {
      $box = $glyphs->renderControl('hud-outcome-key-' . $index, $control,
        new CanvasRectangle($footer->x, $footer->y, $right - $footer->x, $footer->height), HorizontalAlignment::RIGHT);
      $right = $box->x - $pitch->cellWidth;
    }
    $drawn = $glyphs->compose($arena->width, $arena->height);
    array_push($images, ...$drawn->images);
    array_push($text, ...$drawn->textLayers);
  }

  /** A panel's hints as keycaps on the help line when the theme can draw them and they fit; otherwise its help text, reported. */
  private static function renderHelp(array &$images, array &$text, string $id, BattleHudListSnapshot $list,
    CanvasRectangle $bounds, BattleUiSkin $skin, BattleCanvasLayout $arena, ?MenuPresentationCatalog $controls,
    PresentationFallbackReport $fallbacks): void
  {
    $cell = [8, (int)$bounds->height];
    if ($list->hints !== [] && $controls !== null
      && MenuActionHints::height($list->hints, $controls, $bounds->width, true, $cell) <= $bounds->height) {
      $canvas = MenuActionHints::compose($arena->width, $arena->height, 'hud-' . $id . '-help', $list->hints, $controls,
        $bounds, true, $cell, 1002);
      array_push($images, ...$canvas->images);
      array_push($text, ...$canvas->textLayers);
      return;
    }
    if ($list->hints !== []) {
      $fallbacks->report($controls === null
        ? "Battle {$id} hints read as text: the project has no menu theme to draw keycaps."
        : "Battle {$id} hints read as text: their keycaps do not fit the panel's help line.");
    }
    self::line($text, $id . '-help', $list->help, $bounds->x, $bounds->y, $bounds->width, (int)$bounds->height, $skin->colors['muted'], cellWidth: 8);
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
