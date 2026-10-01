<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/** Protocol-v1 sprite geometry; asset containment and PNG loading belong to the renderer. */
final readonly class PresentationSprite
{
  public function __construct(
    public string $id,
    public string $asset,
    public int $x,
    public int $y,
    public int $width,
    public int $height,
    public PresentationSpriteAnchor $anchor = PresentationSpriteAnchor::BOTTOM_CENTER,
    public int $layer = 0,
    public ?SpriteSourceRect $sourceRect = null,
    /** Field sprites only: how the sprite reached this cell. Requires negotiated field_motion. */
    public ?PresentationSpriteMotion $motion = null,
    /**
     * Field sprites only: logical pixels drawn above the cell, which still places and orders the sprite.
     * Sent only to a renderer that negotiated sprite_lift ({@see toArray()}).
     */
    public int $lift = 0,
  )
  {
    if ($id === '' || str_contains($id, "\0") || preg_match('//u', $id) !== 1) {
      throw new InvalidArgumentException('Sprite ID must be nonempty UTF-8 without NUL.');
    }
    SpriteValidation::validateDefinition($asset, $width, $height, $layer);
    SpriteValidation::validateSigned32BitRange($x);
    SpriteValidation::validateSigned32BitRange($y);
    SpriteValidation::validateLift($lift, $height);
  }

  /**
   * The sprite's protocol value. With `$lift`, for a renderer that negotiated
   * sprite_lift, a lifted sprite names its `lift`; without, the value is the
   * unlifted placement every renderer accepts.
   *
   * @return array{id: string, asset: string, x: int, y: int, width: int, height: int, anchor: string, layer: int, sourceRect?: array{x: int, y: int, width: int, height: int}, motion?: array{duration: float}, lift?: int}
   */
  public function toArray(bool $lift = false): array
  {
    $data = ['id' => $this->id, 'asset' => $this->asset, 'x' => $this->x, 'y' => $this->y,
      'width' => $this->width, 'height' => $this->height, 'anchor' => $this->anchor->value, 'layer' => $this->layer];
    if ($this->sourceRect !== null) { $data['sourceRect'] = $this->sourceRect->toArray(); }
    if ($this->motion !== null) { $data['motion'] = $this->motion->toArray(); }
    if ($lift && $this->lift > 0) { $data['lift'] = $this->lift; }
    return $data;
  }

  /** The same sprite placed without a slide, for renderers that cannot present one. */
  public function withoutMotion(): self
  {
    return $this->motion === null ? $this : new self($this->id, $this->asset, $this->x, $this->y, $this->width,
      $this->height, $this->anchor, $this->layer, $this->sourceRect, lift: $this->lift);
  }

  /** @param list<PresentationSprite> $sprites @return list<PresentationSprite> */
  public static function orderedList(array $sprites): array
  {
    if (!array_is_list($sprites) || count($sprites) > 1024) {
      throw new InvalidArgumentException('Presentation sprites must be a list of at most 1024 sprites.');
    }
    $ids = $copy = [];
    foreach ($sprites as $sprite) {
      if (!$sprite instanceof self || isset($ids[$sprite->id])) {
        throw new InvalidArgumentException('Presentation requires typed sprites with unique IDs.');
      }
      $ids[$sprite->id] = true;
      $copy[] = $sprite;
    }
    // Row order within a layer: a sprite lower on the field draws in front; ties keep their order.
    usort($copy, static fn(self $a, self $b) => [$a->layer, $a->y] <=> [$b->layer, $b->y]);
    return $copy;
  }
}
