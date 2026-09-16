<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\Battle\States\BattlePauseState;

/** Transitional adapter: explicit window footprints, never battlefield glyph inference. */
final class BattleCanvasUiAdapter
{
  /** @return list<CanvasTextLayer> */
  public static function collect(BattleScene $scene, BattleCanvasLayout $arena, bool $includeField = false): array
  {
    $screen = $scene->ui;
    if ($screen === null) { return []; }
    $grid = $arena->uiGrid;
    $originX = ($arena->width - $grid->columns * $grid->cellWidth) / 2;
    $originY = ($arena->height - $grid->rows * $grid->cellHeight) / 2;
    $left = $screen->screenDimensions->getLeft();
    $top = $screen->screenDimensions->getTop();
    $owned = $screen->presentationWindows();
    if ($arena->skin !== null) {
      $owned = array_filter($owned, static fn($window) => !in_array($window,
        [$screen->commandWindow, $screen->commandContextWindow, $screen->characterNameWindow,
          $screen->characterStatusWindow, $screen->messageWindow], true));
    }
    $windows = array_map(static fn($window) => [
      $window->getPosition()->x, $window->getPosition()->y, $window->getWidth(), $window->getHeight(),
    ], $owned);
    $layers = [];
    foreach (Console::presentationSnapshot()->textLayers as $layer) {
      if ($includeField && $layer->id === 'world') {
        $field = $screen->fieldWindow;
        $runs = self::clipRuns($layer->runs, [[
          $field->getPosition()->x, $field->getPosition()->y, $field->getWidth(), $field->getHeight(),
        ]], $left, $top, $grid->columns, $grid->rows);
        if ($runs !== []) {
          $layers[] = new CanvasTextLayer('battle-field', 0, $originX, $originY, $grid, $runs);
        }
      }
      $regions = $layer->id === 'world' ? $windows : [[$left, $top, $grid->columns, $grid->rows]];
      $runs = self::clipRuns($layer->runs, $regions, $left, $top, $grid->columns, $grid->rows);
      if ($runs !== []) {
        $layers[] = new CanvasTextLayer('battle-ui-' . count($layers), ($arena->skin === null ? 1000 : 1500) + count($layers), $originX, $originY, $grid, $runs);
      }
    }
    if ($scene->state instanceof BattlePauseState) {
      $layers[] = new CanvasTextLayer('battle-pause', 2000, $originX, $originY, $grid, [
        new PresentationTextRun(intdiv($grid->rows - 1, 2), intdiv($grid->columns - strlen(BattlePauseState::PAUSE_TEXT), 2),
          BattlePauseState::PAUSE_TEXT, background: PresentationColor::rgb(15, 23, 30)),
      ]);
    }
    return $layers;
  }

  /**
   * Named Console overlays own their cells; base cells require an explicit window footprint.
   * @param list<PresentationTextRun> $source
   * @param array<array{int|float, int|float, int, int}> $regions
   * @return list<PresentationTextRun>
   */
  private static function clipRuns(array $source, array $regions, int $left, int $top, int $columns, int $rows): array
  {
    $runs = [];
    foreach ($source as $run) {
      foreach ($regions as [$x, $y, $width, $height]) {
        if ($run->row < $y || $run->row >= $y + $height || $run->row < $top || $run->row >= $top + $rows) { continue; }
        $start = (int)max($x, $left, $run->column);
        $end = (int)min($x + $width, $left + $columns, $run->column + mb_strlen($run->text, 'UTF-8'));
        if ($end <= $start) { continue; }
        $runs[] = new PresentationTextRun($run->row - $top, $start - $left,
          mb_substr($run->text, $start - $run->column, $end - $start, 'UTF-8'),
          $run->foreground, $run->background ?? PresentationColor::rgb(15, 23, 30));
      }
    }
    return $runs;
  }
}
