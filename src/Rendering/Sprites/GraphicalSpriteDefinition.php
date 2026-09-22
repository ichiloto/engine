<?php

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteAnchor;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use InvalidArgumentException;

/** Immutable graphical intent, independent of world position, Camera and transport. */
final readonly class GraphicalSpriteDefinition
{
  public ?SpriteSourceRect $sourceRect;

  public function __construct(
    public string $asset,
    public int $width,
    public int $height,
    public PresentationSpriteAnchor $anchor = PresentationSpriteAnchor::BOTTOM_CENTER,
    public int $layer = 0,
    ?SpriteSourceRect $sourceRect = null,
    public ?SpriteSheet $sheet = null,
  )
  {
    SpriteValidation::validateDefinition($asset, $width, $height, $layer);
    $this->sourceRect = $sourceRect ?? $sheet?->sourceRect($sheet->idleFrame);
  }

  public function atFrame(int $frame): self
  {
    return $this->sheet === null ? $this : new self($this->asset, $this->width, $this->height,
      $this->anchor, $this->layer, $this->sheet->sourceRect($frame), $this->sheet);
  }

  /** A single pose, optionally cropped from a sheet; no playback state is stored here. */
  public static function fromArray(array $data): self
  {
    $data += ['anchor' => 'bottom_center', 'layer' => 0];
    if (array_diff_key($data, array_flip(['asset', 'width', 'height', 'anchor', 'layer', 'sourceRect'])) !== []
      || !is_string($data['asset'] ?? null) || !is_string($data['anchor'])
      || !is_int($data['width'] ?? null) || !is_int($data['height'] ?? null) || !is_int($data['layer'])) {
      throw new InvalidArgumentException('Graphical sprite requires a string asset/anchor and integer width/height/layer, with no unknown fields.');
    }
    $anchor = PresentationSpriteAnchor::tryFrom($data['anchor']);
    if ($anchor === null) {
      throw new InvalidArgumentException('Graphical sprite has an unsupported anchor.');
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
    return new self($data['asset'], $data['width'], $data['height'], $anchor, $data['layer'], $source);
  }
}
