<?php

namespace Ichiloto\Engine\IO\Console;

use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use RuntimeException;

/** Row-local counterpart of the full reference projection; never reads other rows. */
final class ConsolePresentationRowComposer
{
  private const int MAX_CACHED_CELLS = 4096;
  private array $parsedCells = [];

  /** @return array<string, list<PresentationTextRun>> */
  public function composeRow(int $row, int $width, array $world, array $entries, array $priorities,
    array $overlays, array $excluded, array $excludedWorld): array
  {
    $named = [];
    foreach ($entries as $x => $entry) {
      if ($entry['base'] === null) { unset($world[$x]); }
      elseif (array_key_exists($x, $world)) { $world[$x] = $entry['base']; }
      foreach ($entry['layers'] as $id => $cell) {
        if (isset($excludedWorld[$id])) {
          unset($world[$x]);
          foreach (array_keys($entry['layers']) as $underlay) {
            if ($underlay === $id) { break; }
            unset($named[$underlay][$x]);
          }
        } elseif (!isset($excluded[$id])) {
          $named[$id][$x] = $cell;
        }
      }
    }
    $planes = ['world' => $world];
    foreach ($priorities as $id => $_) {
      if (isset($named[$id])) { $planes[$id] = $named[$id]; }
    }
    foreach ($planes as $id => $cells) { $planes[$id] = self::repairWholeGlyphs($cells); }
    foreach ($named as $id => $cells) {
      foreach ($planes as $lowerId => &$lower) {
        if ($lowerId === $id) { break; }
        self::maskWideGlyphs($lower, $cells, $width);
      }
      unset($lower);
    }
    foreach ($overlays as $id => $overlay) {
      if (isset($excluded[$id]) || !isset($overlay['rows'][$row])) { continue; }
      $cells = [];
      foreach ($overlay['rows'][$row] as $x => $symbol) {
        if ($symbol === NormalizedRow::CONTINUATION) { continue; }
        $span = NormalizedRow::symbolWidth($symbol);
        if ($x + $span > $width) { continue; }
        for ($column = $x; $column < $x + $span; $column++) {
          foreach ($entries[$column]['layers'] ?? [] as $layer => $_) {
            if (isset($named[$layer][$column]) && ($priorities[$layer] ?? 0) > $overlay['priority']) { continue 3; }
          }
        }
        $cells[$x] = $symbol;
        for ($column = $x + 1; $column < $x + $span; $column++) { $cells[$column] = NormalizedRow::CONTINUATION; }
      }
      foreach ($planes as $lowerId => &$lower) {
        if (($priorities[$lowerId] ?? 0) <= $overlay['priority']) { self::maskWideGlyphs($lower, $cells, $width); }
      }
      unset($lower);
      $planes[$id] = $cells;
      $priorities[$id] = $overlay['priority'];
    }
    $result = [];
    foreach ($planes as $id => $cells) {
      $result[$id] = $this->createRuns($row, $cells);
    }
    return $result;
  }

  /** @return array<int, string> */
  private static function repairWholeGlyphs(array $cells): array
  {
    foreach ($cells as $x => $cell) {
      if ($cell === NormalizedRow::CONTINUATION) {
        $anchor = self::findAnchor($cells, $x);
        if ($anchor === null || $anchor + NormalizedRow::symbolWidth($cells[$anchor]) <= $x) { $cells[$x] = ' '; }
        continue;
      }
      for ($offset = 1, $width = NormalizedRow::symbolWidth($cell); $offset < $width; $offset++) {
        if (($cells[$x + $offset] ?? null) !== NormalizedRow::CONTINUATION) { $cells[$x] = ' '; break; }
      }
    }
    return $cells;
  }

  private static function maskWideGlyphs(array &$lower, array $upper, int $width): void
  {
    foreach (array_keys($upper) as $column) {
      $anchor = self::findAnchor($lower, $column);
      if ($anchor === null || !isset($lower[$anchor])) { continue; }
      $span = NormalizedRow::symbolWidth($lower[$anchor]);
      if ($span <= 1) { continue; }
      for ($x = $anchor; $x < min($width, $anchor + $span); $x++) { $lower[$x] = ' '; }
    }
  }

  private static function findAnchor(array $cells, int $column): ?int
  {
    if (!isset($cells[$column])) { return null; }
    while ($column >= 0 && ($cells[$column] ?? ' ') === NormalizedRow::CONTINUATION) { $column--; }
    return $column >= 0 ? $column : null;
  }

  /** @return list<PresentationTextRun> */
  private function createRuns(int $row, array $cells): array
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
          if (preg_match('//u', $cell) !== 1) { throw new RuntimeException("Console row {$row} must contain valid UTF-8 text."); }
          if (count($this->parsedCells) >= self::MAX_CACHED_CELLS) { unset($this->parsedCells[array_key_first($this->parsedCells)]); }
          $this->parsedCells[$cell] = [SgrColorParser::parse($cell), TerminalText::rendererScalar($cell)];
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
    if ($text !== '') { $runs[] = new PresentationTextRun($row, $start, $text, $style['foreground'], $style['background']); }
    return $runs;
  }
}
