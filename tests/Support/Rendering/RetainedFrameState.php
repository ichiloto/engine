<?php

namespace Tests\Support\Rendering;

use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererMessage;
use RuntimeException;

/** Test-side materialization, not a second production sender or wire format. */
final class RetainedFrameState
{
  private int $generation = 0;
  private int $frame = 0;
  private array $values = [];
  private array $textRows = [];
  /** World ID / owner layer ID / row / column / complete glyph cell. */
  private array $worldGlyphRows = [];
  /** World ID / tile layer ID / row / column / complete tile cell. */
  private ?array $viewport = null;

  /** @param list<RendererMessage> $messages @return list<array<string, mixed>> */
  public static function replay(array $messages): array
  {
    $state = new self();
    $frames = [];
    foreach ($messages as $message) {
      if ($state->applyMessage($message)) { $frames[] = $state->getFrame(); }
    }
    return $frames;
  }

  /** Returns whether this transaction is ready to display, not merely staged. */
  public function applyMessage(RendererMessage $message): bool
  {
    $payload = $message->payload;
    if ($message->protocol !== RendererProtocolVersion::V2 || !isset($payload['operations'])) {
      throw new RuntimeException('Expected retained v2 operations, not a stateless frame.');
    }
    if (!$payload['reset'] && $payload['baseGeneration'] !== $this->generation) {
      throw new RuntimeException('Retained replay requires the preceding generation or an explicit reset.');
    }
    if ($payload['generation'] !== $payload['baseGeneration'] + 1) {
      throw new RuntimeException('Retained generations must advance exactly once per packet.');
    }
    if ($payload['reset']) {
      $this->values = $this->textRows = $this->worldGlyphRows = [];
      $this->viewport = null;
    }
    foreach ($payload['operations'] as $operation) {
      $id = $operation['id'];
      switch ($operation['op']) {
        case 'put':
          $kind = $operation['kind'];
          $this->values[$kind][$id] = $operation['value'];
          if ($kind === 'text') {
            $this->textRows[$id] = [];
            foreach ($operation['value']['runs'] as $run) { $this->textRows[$id][$run['row']][] = $run; }
          }
          if ($kind === 'world') {
            unset($this->worldGlyphRows[$id]);
          }
          break;
        case 'remove':
          unset($this->values[$operation['kind']][$id]);
          if ($operation['kind'] === 'text') { unset($this->textRows[$id]); }
          if ($operation['kind'] === 'world') { unset($this->worldGlyphRows[$id]); }
          break;
        case 'textRows':
          if (!isset($this->values['text'][$id])) { throw new RuntimeException('Cannot patch an absent text layer.'); }
          foreach ($operation['rows'] as $row) {
            if ($row['runs'] === []) { unset($this->textRows[$id][$row['row']]); }
            else { $this->textRows[$id][$row['row']] = $row['runs']; }
          }
          break;
        case 'worldRows':
          if (!isset($this->values['world'][$id])) { throw new RuntimeException('Cannot patch an absent world.'); }
          foreach ($operation['rows'] as $row) {
            // A composed glyph row replaces all old owners, including newly empty cells.
            foreach (array_keys($this->worldGlyphRows[$id] ?? []) as $layerId) {
              unset($this->worldGlyphRows[$id][$layerId][$row['row']]);
              if ($this->worldGlyphRows[$id][$layerId] === []) { unset($this->worldGlyphRows[$id][$layerId]); }
            }
            foreach ($row['cells'] as $column => $cell) {
              $this->worldGlyphRows[$id][$cell['ownerLayerId']][$row['row']][$column] = $cell;
            }
          }
          break;

        default: throw new RuntimeException('Unknown retained test operation: ' . $operation['op']);
      }
    }
    if (array_key_exists('viewport', $payload)) { $this->viewport = $payload['viewport']; }
    $this->generation = $payload['generation'];
    $this->frame = $payload['frame'];
    return $payload['present'];
  }

  /** Materialized semantic values omit wire-only ordering metadata. */
  public function getFrame(): array
  {
    $text = $this->getValues('text');
    foreach ($text as &$layer) {
      $rows = $this->textRows[$layer['id']] ?? [];
      ksort($rows, SORT_NUMERIC);
      $layer['runs'] = array_merge([], ...array_values($rows));
    }
    unset($layer);
    $frame = ['frame' => $this->frame, 'textLayers' => $text, 'sprites' => $this->getValues('sprite')];
    if (isset($this->values['canvas']['canvas'])) {
      $canvas = $this->values['canvas']['canvas'];
      $frame['canvas'] = ['width' => $canvas['width'], 'height' => $canvas['height'],
        'images' => $this->getValues('canvas_image'), 'indicators' => $this->getValues('canvas_indicator'),
        'textLayers' => $this->getValues('canvas_text')];
      $composites = $this->getValues('canvas_composite');
      if ($composites !== []) { $frame['canvas']['composites'] = $composites; }
    }
    if ($this->viewport !== null) { $frame['viewport'] = $this->viewport; }
    foreach ($this->values['world'] ?? [] as $id => $world) {
      $frame['worlds'][$id] = [...$world, 'glyphRows' => $this->worldGlyphRows[$id] ?? []];
    }
    return $frame;
  }

  /** Compose scalar text cells in replayed paint order, including opaque spaces. */
  public static function getTextRows(array $frame, int $columns, int $rows): array
  {
    $cells = array_fill(0, $rows, array_fill(0, $columns, ' '));
    $layers = $frame['textLayers'];
    usort($layers, static fn(array $a, array $b): int => $a['layer'] <=> $b['layer']);
    foreach ($layers as $layer) {
      foreach ($layer['runs'] as $run) {
        foreach (mb_str_split($run['text'], 1, 'UTF-8') as $offset => $glyph) {
          $cells[$run['row']][$run['column'] + $offset] = $glyph;
        }
      }
    }
    return array_map(static fn(array $row): string => implode('', $row), $cells);
  }

  private function getValues(string $kind): array
  {
    $values = array_values($this->values[$kind] ?? []);
    usort($values, static fn(array $a, array $b) => ($a['layer'] ?? 0) <=> ($b['layer'] ?? 0)
      ?: ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
    foreach ($values as &$value) { unset($value['order']); }
    return $values;
  }
}
