<?php

namespace Ichiloto\Engine\Rendering\Presentation;

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
    if ($asset === '' || preg_match('//u', $asset) !== 1 || str_contains($asset, "\0")
      || str_starts_with($asset, '/') || str_contains($asset, '\\')
      || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $asset) === 1
      || in_array('..', explode('/', $asset), true)) {
      throw new InvalidArgumentException('Sprite asset must be a relative UTF-8 path using forward slashes, without NUL or parent traversal.');
    }
    if ($width < 1 || $height < 1 || $width > 4096 || $height > 4096) {
      throw new InvalidArgumentException('Sprite dimensions must be between 1 and 4096 logical pixels.');
    }
    foreach ([$x, $y, $layer] as $value) {
      if ($value < -2147483648 || $value > 2147483647) {
        throw new InvalidArgumentException('Sprite coordinates and layer must fit protocol-v1 signed 32-bit integers.');
      }
    }
  }

  /** @return array{id: string, asset: string, x: int, y: int, width: int, height: int, anchor: string, layer: int} */
  public function toArray(): array
  {
    return [...get_object_vars($this), 'anchor' => $this->anchor->value];
  }
}
