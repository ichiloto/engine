<?php

namespace Ichiloto\Engine\IO\Console;

use Ichiloto\Engine\Diagnostics\LatencyTrace;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use InvalidArgumentException;
use RuntimeException;

/** Shared scalar-aligned projection for retained Console cells and authoring previews. */
final class TerminalPresentationComposer
{
  private const int MAX_CACHED_CELLS = 4096;
  private array $parsedCells = [];
  private int $styleParsingNanoseconds = 0;

  /** @param array<int, array<int, string>> $rows @return list<PresentationTextRun> */
  public function createRuns(array $rows): array
  {
    $started = LatencyTrace::getTimeNow();
    $before = $this->styleParsingNanoseconds;
    $runs = [];
    foreach ($rows as $row => $cells) {
      array_push($runs, ...$this->createRunsForRow($row, $cells));
    }
    LatencyTrace::end('console.runs', $started, [
      'style_parse_ns' => $this->styleParsingNanoseconds - $before,
      'unique_cells' => count($this->parsedCells), 'runs' => count($runs),
    ]);
    return $runs;
  }

  /** @param array<int, string> $cells @return list<PresentationTextRun> */
  public function createRunsForRow(int $row, array $cells): array
  {
    ksort($cells, SORT_NUMERIC);
    $runs = [];
    $text = '';
    $start = $previous = -1;
    $style = ['foreground' => null, 'background' => null];
    foreach ($cells as $x => $cell) {
      if ($cell === NormalizedRow::CONTINUATION) {
        $next = $previous === $x - 1 ? $style : ['foreground' => null, 'background' => null];
        $glyph = ' ';
      } else {
        if (!isset($this->parsedCells[$cell])) {
          if (preg_match('//u', $cell) !== 1) {
            throw new RuntimeException("Console row {$row} must contain valid UTF-8 text.");
          }
          if (count($this->parsedCells) >= self::MAX_CACHED_CELLS) {
            unset($this->parsedCells[array_key_first($this->parsedCells)]);
          }
          $parseStart = LatencyTrace::getTimeNow();
          $this->parsedCells[$cell] = [SgrColorParser::parse($cell), TerminalText::rendererScalar($cell)];
          if ($parseStart !== null) {
            $this->styleParsingNanoseconds += LatencyTrace::getTimeNow() - $parseStart;
          }
        }
        [$next, $glyph] = $this->parsedCells[$cell];
      }
      if ($text !== '' && ($x !== $previous + 1 || $next != $style)) {
        $runs[] = new PresentationTextRun($row, $start, $text, $style['foreground'], $style['background']);
        $text = '';
      }
      if ($text === '') { $start = $x; }
      $text .= $glyph;
      $style = $next;
      $previous = $x;
    }
    if ($text !== '') {
      $runs[] = new PresentationTextRun($row, $start, $text, $style['foreground'], $style['background']);
    }
    return $runs;
  }

  /** Styled source lines remain untouched; complete glyphs are clipped to the explicit grid.
   * @param list<string> $lines @return list<PresentationTextRun>
   */
  public function createRunsFromLines(array $lines, RendererGridConfig $grid): array
  {
    if (!array_is_list($lines) || count($lines) > $grid->rows) {
      throw new InvalidArgumentException('Terminal preview lines must be a list fitting the authored grid.');
    }
    $rows = [];
    foreach ($lines as $row => $line) {
      if (!is_string($line) || preg_match('//u', $line) !== 1) {
        throw new InvalidArgumentException('Terminal preview lines require valid UTF-8 strings.');
      }
      $rows[$row] = NormalizedRow::fromText($line)->clippedCells($grid->columns);
    }
    return $this->createRuns($rows);
  }

  /** @param list<string> $lines */
  public function createCanvasFromLines(array $lines, RendererGridConfig $grid,
    string $id = 'terminal-preview'): PresentationCanvas
  {
    return new PresentationCanvas($grid->columns * $grid->cellWidth, $grid->rows * $grid->cellHeight,
      textLayers: [new CanvasTextLayer($id, 0, 0, 0, $grid, $this->createRunsFromLines($lines, $grid))]);
  }
}
