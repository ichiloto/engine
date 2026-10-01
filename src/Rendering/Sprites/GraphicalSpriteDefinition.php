<?php

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteAnchor;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use InvalidArgumentException;

/**
 * Immutable graphical intent, independent of world position, Camera and
 * transport. Field art is sized in whole character frames (FieldViewport::TILE_SIZE),
 * never authored pixels: width and height here are derived from them for the
 * presentation protocol. A lift, in the same pixels, draws the art that far
 * above its cell without moving the cell ({@see CharacterSheet::LIFT}).
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
    public int $lift = 0,
    public int $quarterTurns = 0,
  )
  {
    SpriteValidation::validateDefinition($asset, $width, $height, $layer);
    SpriteValidation::validateLift($lift, $height);
    if ($quarterTurns < 0 || $quarterTurns > 3) {
      throw new InvalidArgumentException('Sprite quarterTurns must be an integer from 0 to 3.');
    }
  }

  /**
   * A single image drawn on the field, such as a cinematic pose: `asset`,
   * optional `sourceRect`, optional `layer`, and an optional size in whole
   * character frames, `cells` (default one), bottom-centred on its position.
   *
   * @param array<string, mixed> $data
   */
  public static function fromArray(array $data): self
  {
    if (array_diff_key($data, array_flip(['asset', 'sourceRect', 'layer', 'cells'])) !== []
      || !is_string($data['asset'] ?? null) || !is_int($data['layer'] ?? 0)) {
      throw new InvalidArgumentException('A field image accepts only asset, sourceRect, layer and cells; it is sized in character frames, not pixels.');
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
    return new self($data['asset'], $cells['width'] * FieldViewport::TILE_SIZE,
      $cells['height'] * FieldViewport::TILE_SIZE, PresentationSpriteAnchor::BOTTOM_CENTER, $data['layer'] ?? 0, $source);
  }
}
