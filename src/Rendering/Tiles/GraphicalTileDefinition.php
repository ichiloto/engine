<?php

namespace Ichiloto\Engine\Rendering\Tiles;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Rendering\Presentation\PresentationTileBatch;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/** Optional map presentation, independent of collision and saved game state. */
final readonly class GraphicalTileDefinition
{
  private const int MAX_ASSET_PATH_BYTES = 4096;
  /** @param list<SpriteSourceRect> $sources @param array<string|int, int> $symbols */
  private function __construct(public string $asset, public array $sources, public array $symbols) {}

  public static function fromArray(mixed $data, string $context): self
  {
    try {
      if (!is_array($data) || array_diff_key($data, array_flip(['asset', 'symbols'])) !== []
        || !is_string($data['asset'] ?? null) || !is_array($data['symbols'] ?? null)
        || $data['symbols'] === [] || count($data['symbols']) > PresentationTileBatch::MAX_SOURCES) {
        throw new InvalidArgumentException('requires asset and 1..256 explicit symbol rectangles, with no unknown fields.');
      }
      self::validateAsset($data['asset']);
      $sources = $symbols = $indices = [];
      foreach ($data['symbols'] as $key => $rect) {
        $symbol = TerminalText::stripAnsi((string)$key);
        if (preg_match('//u', $symbol) !== 1 || preg_match('/\p{Cc}/u', $symbol) === 1
          || TerminalText::symbolCount($symbol) !== 1 || TerminalText::displayWidth($symbol) !== 1
          || isset($symbols[$symbol])) {
          throw new InvalidArgumentException('symbols must have unique normalized, control-free, single-column keys.');
        }
        if (!is_array($rect) || array_diff_key($rect, array_flip(['x', 'y', 'width', 'height'])) !== []) {
          throw new InvalidArgumentException("symbol '$symbol' requires an x/y/width/height rectangle.");
        }
        foreach (['x', 'y', 'width', 'height'] as $field) {
          if (!is_int($rect[$field] ?? null)) {
            throw new InvalidArgumentException("symbol '$symbol' rectangle '$field' must be an integer.");
          }
        }
        $source = new SpriteSourceRect($rect['x'], $rect['y'], $rect['width'], $rect['height']);
        $identity = implode(':', $source->toArray());
        if (!isset($indices[$identity])) {
          $indices[$identity] = count($sources);
          $sources[] = $source;
        }
        $symbols[$symbol] = $indices[$identity];
      }
      return new self($data['asset'], $sources, $symbols);
    } catch (InvalidArgumentException $error) {
      throw new InvalidArgumentException("$context tiles2d: " . $error->getMessage(), 0, $error);
    }
  }

  /** @return array<string, self> */
  public static function getForLayers(mixed $data, MapLayerSet $layers, string $context): array
  {
    if ($layers->legacy) {
      return ['terrain' => self::fromArray($data, $context)];
    }
    if (!is_array($data) || array_diff_key($data, array_flip(['asset', 'layers'])) !== []
      || !is_array($data['layers'] ?? null) || $data['layers'] === []) {
      throw new InvalidArgumentException("{$context} tiles2d: requires per-layer symbol tables under layers, with an optional shared asset.");
    }
    $names = array_column($layers->layers, 'name');
    if (array_key_exists('asset', $data)) {
      try {
        self::validateAsset($data['asset']);
      } catch (InvalidArgumentException $error) {
        throw new InvalidArgumentException("{$context} tiles2d: " . $error->getMessage(), previous: $error);
      }
    }
    $definitions = [];
    foreach ($data['layers'] as $name => $definition) {
      if (!in_array($name, $names, true) || !is_array($definition)) {
        throw new InvalidArgumentException("{$context} tiles2d: unknown or malformed layer {$name}.");
      }
      if (!array_key_exists('asset', $definition) && array_key_exists('asset', $data)) {
        $definition['asset'] = $data['asset'];
      }
      $definitions[$name] = self::fromArray($definition, "{$context} layer {$name}");
    }
    if (count($definitions) > PresentationTileBatch::MAX_BATCHES
      || array_sum(array_map(static fn(self $definition): int => count($definition->sources), $definitions)) > PresentationTileBatch::MAX_FRAME_SOURCES) {
      throw new InvalidArgumentException("{$context} tiles2d: layer catalog exceeds the renderer's batch/source limits.");
    }
    return $definitions;
  }

  private static function validateAsset(mixed $asset): void
  {
    if (!is_string($asset) || strlen($asset) > self::MAX_ASSET_PATH_BYTES
      || strtolower(pathinfo($asset, PATHINFO_EXTENSION)) !== 'png') {
      throw new InvalidArgumentException('asset must be a relative PNG path of at most ' . self::MAX_ASSET_PATH_BYTES . ' bytes.');
    }
    SpriteValidation::validateAssetPath($asset);
  }

  /** @param array<string, self> $definitions */
  public static function validateDecoration(MapLayerSet $layers, array $definitions): void
  {
    foreach ($layers->layers as $layer) {
      if (!$layer->decoration) { continue; }
      $symbols = $definitions[$layer->name]->symbols ?? [];
      foreach ($layer->grid as $y => $row) {
        foreach ($row as $x => $cell) {
          $glyph = TerminalText::stripAnsi($cell);
          if ($glyph !== ' ' && !array_key_exists($glyph, $symbols)) {
            throw new InvalidArgumentException(sprintf('Decoration layer %s: glyph %s at row %d, column %d has no crop mapping.',
              $layer->path, var_export($glyph, true), $y, $x));
          }
        }
      }
    }
  }
}
