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
  )
  {
    if ($id === '' || str_contains($id, "\0") || preg_match('//u', $id) !== 1) {
      throw new InvalidArgumentException('Sprite ID must be nonempty UTF-8 without NUL.');
    }
    SpriteValidation::validateDefinition($asset, $width, $height, $layer);
    SpriteValidation::validateSigned32BitRange($x);
    SpriteValidation::validateSigned32BitRange($y);
  }

  /** @return array{id: string, asset: string, x: int, y: int, width: int, height: int, anchor: string, layer: int} */
  public function toArray(): array
  {
    return [...get_object_vars($this), 'anchor' => $this->anchor->value];
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
    usort($copy, static fn(self $a, self $b) => $a->layer <=> $b->layer);
    return $copy;
  }
}
