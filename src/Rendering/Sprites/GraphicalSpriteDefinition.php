<?php

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteAnchor;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use InvalidArgumentException;

/**
 * Immutable graphical intent, independent of world position, Camera and
 * transport. Field art is sized in whole field cells, never authored pixels:
 * width and height here are derived from cells for the presentation protocol.
 */
final readonly class GraphicalSpriteDefinition
{
  public function __construct(
    public string $asset,
    public int $width,
    public int $height,
    public PresentationSpriteAnchor $anchor = PresentationSpriteAnchor::BOTTOM_CENTER,
    public int $layer = 0,
    public ?SpriteSourceRect $sourceRect = null,
  )
  {
    SpriteValidation::validateDefinition($asset, $width, $height, $layer);
  }

  /**
   * A single image drawn on the field, such as a cinematic pose: `asset`,
   * optional `sourceRect`, optional `layer`, and an optional footprint in
   * whole `cells` (default one cell), bottom-centred on its position.
   *
   * @param array<string, mixed> $data
   */
  public static function fromArray(array $data): self
  {
    if (array_diff_key($data, array_flip(['asset', 'sourceRect', 'layer', 'cells'])) !== []
      || !is_string($data['asset'] ?? null) || !is_int($data['layer'] ?? 0)) {
      throw new InvalidArgumentException('A field image accepts only asset, sourceRect, layer and cells; it is sized in field cells, not pixels.');
    }
    $cells = $data['cells'] ?? ['width' => 1, 'height' => 1];
    if (!is_array($cells) || array_diff_key($cells, array_flip(['width', 'height'])) !== []
      || !is_int($cells['width'] ?? null) || !is_int($cells['height'] ?? null)
      || $cells['width'] < 1 || $cells['height'] < 1) {
      throw new InvalidArgumentException('A field image footprint requires positive integer cells width and height.');
    }
    $source = null;
    if (array_key_exists('sourceRect', $data)) {
      $rect = $data['sourceRect'];
      if (!is_array($rect) || count($rect) !== 4) {
        throw new InvalidArgumentException('Sprite sourceRect requires integer x, y, width and height.');
      }
      foreach (['x', 'y', 'width', 'height'] as $key) {
        if (!is_int($rect[$key] ?? null)) {
          throw new InvalidArgumentException('Sprite sourceRect requires integer x, y, width and height.');
        }
      }
      $source = new SpriteSourceRect($rect['x'], $rect['y'], $rect['width'], $rect['height']);
    }
    return new self($data['asset'], $cells['width'] * FieldViewport::CELL_SIZE,
      $cells['height'] * FieldViewport::CELL_SIZE, PresentationSpriteAnchor::BOTTOM_CENTER, $data['layer'] ?? 0, $source);
  }
}
