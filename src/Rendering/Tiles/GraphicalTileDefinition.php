<?php

namespace Ichiloto\Engine\Rendering\Tiles;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/** Optional map presentation, independent of collision and saved game state. */
final readonly class GraphicalTileDefinition
{
  /** @param list<SpriteSourceRect> $sources @param array<string|int, int> $symbols */
  private function __construct(public string $asset, public array $sources, public array $symbols) {}

  public static function fromArray(mixed $data, string $context): self
  {
    try {
      if (!is_array($data) || array_diff_key($data, array_flip(['asset', 'symbols'])) !== []
        || !is_string($data['asset'] ?? null) || !is_array($data['symbols'] ?? null)
        || $data['symbols'] === [] || count($data['symbols']) > 256) {
        throw new InvalidArgumentException('requires asset and 1..256 explicit symbol rectangles, with no unknown fields.');
      }
      SpriteValidation::validateAssetPath($data['asset']);
      if (strlen($data['asset']) > 4096 || strtolower(pathinfo($data['asset'], PATHINFO_EXTENSION)) !== 'png') {
        throw new InvalidArgumentException('asset must be a relative PNG path of at most 4096 bytes.');
      }
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
}
