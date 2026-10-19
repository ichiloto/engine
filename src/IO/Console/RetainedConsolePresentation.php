<?php

namespace Ichiloto\Engine\IO\Console;

use Ichiloto\Engine\Rendering\Presentation\StyledPresentationFrame;
use OverflowException;

/** One local consumer cursor. Console owns mutations; the runtime owns reliable delivery. */
final class RetainedConsolePresentation
{
  private array $dirtyRows = [];
  private array $layerRows = [];
  private array $rowLayers = [];
  private array $rows = [];
  private array $priorities = [];
  private array $sourcePriorities = [];
  private array $order = [];
  private array $excluded = [];
  private array $excludedWorld = [];
  private ?array $dimensions = null;
  private bool $retainedWorld = false;
  private int $composedRows = 0;
  private int $runCount = 0;
  private int $scalarCount = 0;
  private array $rowSizes = [];
  private ConsolePresentationRowComposer $composer;

  public function __construct() { $this->composer = new ConsolePresentationRowComposer(); }

  public function __clone() { $this->composer = clone $this->composer; }

  public function markRowDirty(int $row): void { $this->dirtyRows[$row] = true; }

  /** Includes writes not yet consumed by the retained producer. */
  public function getCandidateLayerRows(string $id): array
  {
    return array_keys(($this->layerRows[$id] ?? []) + $this->dirtyRows);
  }

  public function collectChanges(int $width, int $height, array $buffer, array $entries, array $baseCells,
    array $priorities, array $overlays, array $excludedLayers, bool $reset, array $excludedWorldLayers,
    bool $retainedWorld): ConsolePresentationChanges
  {
    $reset = $reset || $this->dimensions !== [$width, $height];
    $excluded = array_fill_keys($excludedLayers, true);
    $excludedWorld = array_fill_keys($excludedWorldLayers, true);
    foreach (array_keys(array_diff_key($this->excluded, $excluded) + array_diff_key($excluded, $this->excluded)
      + array_diff_key($this->excludedWorld, $excludedWorld) + array_diff_key($excludedWorld, $this->excludedWorld)) as $id) {
      $this->dirtyRows += $this->layerRows[$id] ?? [];
    }
    $sourcePriorities = ['world' => 0] + $priorities;
    foreach ($overlays as $id => $overlay) { $sourcePriorities[$id] = $overlay['priority']; }
    if ($sourcePriorities !== $this->sourcePriorities) {
      foreach (array_keys($sourcePriorities + $this->sourcePriorities) as $id) {
        // Drawing order also affects equal-priority wide underlays and overlays.
        $this->dirtyRows += $this->layerRows[$id] ?? [];
      }
    }
    if ($reset || $retainedWorld !== $this->retainedWorld) {
      $this->dirtyRows += array_fill_keys(array_keys($buffer + $entries + $baseCells + $this->rowLayers), true);
      foreach ($overlays as $overlay) { $this->dirtyRows += array_fill_keys(array_keys($overlay['rows']), true); }
      if (!$retainedWorld) { $this->dirtyRows += array_fill(0, $height, true); }
    }
    if ($reset) {
      $this->rows = $this->rowLayers = $this->layerRows = $this->rowSizes = [];
      $this->runCount = $this->scalarCount = 0;
    }
    $changed = [];
    ksort($this->dirtyRows, SORT_NUMERIC);
    foreach (array_keys($this->dirtyRows) as $row) {
      if ($row < 0 || $row >= $height) { continue; }
      foreach ($this->rowLayers[$row] ?? [] as $id => $_) {
        unset($this->layerRows[$id][$row]);
      }
      $sourceIds = [];
      foreach ($entries[$row] ?? [] as $entry) {
        foreach ($entry['layers'] as $id => $_) { $sourceIds[$id] = true; }
      }
      foreach ($overlays as $id => $overlay) {
        if (isset($overlay['rows'][$row])) { $sourceIds[$id] = true; }
      }
      $this->rowLayers[$row] = $sourceIds;
      foreach ($sourceIds as $id => $_) { $this->layerRows[$id][$row] = true; }
      $world = $retainedWorld ? ($baseCells[$row] ?? []) : ($buffer[$row] ?? array_fill(0, $width, ' '));
      $projected = $this->composer->composeRow($row, $width, $world, $entries[$row] ?? [], $priorities,
        $overlays, $excluded, $excludedWorld);
      $this->composedRows++;
      foreach (array_keys($this->rows + $projected) as $id) {
        $runs = $projected[$id] ?? [];
        $previous = $this->rows[$id][$row] ?? [];
        if ($reset || $runs != $previous) {
          $changed[$id][$row] = $runs;
          $scalars = 0;
          foreach ($runs as $run) { $scalars += mb_strlen($run->text, 'UTF-8'); }
          $this->runCount += count($runs) - count($previous);
          $this->scalarCount += $scalars - ($this->rowSizes[$id][$row] ?? 0);
          if ($scalars === 0) { unset($this->rowSizes[$id][$row]); }
          else { $this->rowSizes[$id][$row] = $scalars; }
        }
        if ($runs === []) {
          unset($this->rows[$id][$row]);
          if (($this->rows[$id] ?? []) === []) { unset($this->rows[$id]); }
        } else {
          $this->rows[$id][$row] = $runs;
        }
      }
    }
    $active = ['world' => 0];
    foreach ($priorities as $id => $priority) {
      if (isset($this->rows[$id])) { $active[$id] = $priority; }
    }
    foreach ($overlays as $id => $overlay) {
      if (!isset($excluded[$id])) { $active[$id] = $overlay['priority']; }
    }
    if (count($active) > StyledPresentationFrame::MAX_TEXT_LAYERS) {
      throw new OverflowException('Retained Console text exceeds the presentation layer limit.');
    }
    if ($this->runCount > StyledPresentationFrame::MAX_TEXT_RUNS || $this->scalarCount > StyledPresentationFrame::MAX_TEXT_SCALARS) {
      throw new OverflowException('Retained Console text exceeds renderer run/scalar limits.');
    }
    asort($active, SORT_NUMERIC);
    $order = array_map('strval', array_keys($active));
    $layers = [];
    foreach ($active as $id => $priority) {
      if (!$reset && !isset($changed[$id]) && ($this->priorities[$id] ?? null) === $priority) { continue; }
      $updates = [];
      foreach (($reset ? ($this->rows[$id] ?? []) : ($changed[$id] ?? [])) as $row => $runs) {
        $updates[] = ['row' => $row, 'runs' => $runs];
      }
      usort($updates, static fn(array $a, array $b) => $a['row'] <=> $b['row']);
      $layers[] = ['id' => (string)$id, 'layer' => $priority, 'rows' => $updates];
    }
    $result = new ConsolePresentationChanges($width, $height, $reset, $layers,
      $reset ? [] : array_map('strval', array_keys(array_diff_key($this->priorities, $active))),
      $reset || $order !== $this->order ? $order : null);
    $this->dimensions = [$width, $height];
    $this->retainedWorld = $retainedWorld;
    $this->excluded = $excluded;
    $this->excludedWorld = $excludedWorld;
    $this->sourcePriorities = $sourcePriorities;
    $this->priorities = $active;
    $this->order = $order;
    $this->dirtyRows = [];
    return $result;
  }
}
