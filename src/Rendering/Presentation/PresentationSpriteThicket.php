<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use InvalidArgumentException;

/**
 * How the foliage of the cell a field character stands in hides it
 * ({@see \Ichiloto\Engine\Rendering\Tilesets\TilesetThicket}): the lower
 * `depth` logical pixels of its drawn image, up from its feet, are drawn
 * behind the foliage at `opacity` (0 masks them). With a rustle, the
 * renderer parts the foliage art its world layer `rustle.layer` shows in
 * that cell around the character while it walks, at display rate along the
 * same slide that draws the step.
 *
 * The renderer draws the hiding change along a slide too: a character
 * walking into a thicket sinks into it over the step, and rises out of it
 * walking out, so neither snaps between cells. Without a slide it changes at
 * once.
 *
 * Presentation only, sent to a renderer that negotiated sprite_thicket;
 * others draw the whole character as before. The game leaves the rustle off
 * under reduced motion and keeps the hiding.
 */
final readonly class PresentationSpriteThicket
{
  /**
   * @param int $depth Logical pixels hidden, up from the bottom of the drawn image; at least 1.
   * @param float $opacity How opaque the hidden part is drawn, from 0 to below 1.
   * @param string|null $rustleLayer The retained world layer whose tile in the cell is the foliage that rustles; null for none.
   */
  public function __construct(public int $depth, public float $opacity, public ?string $rustleLayer = null)
  {
    if ($depth < 1) {
      throw new InvalidArgumentException('A sprite thicket hides at least one logical pixel.');
    }
    if (!is_finite($opacity) || $opacity < 0.0 || $opacity >= 1.0) {
      throw new InvalidArgumentException('A sprite thicket draws its hidden part at an opacity from 0 to below 1.');
    }
    if ($rustleLayer === '' || ($rustleLayer !== null && (str_contains($rustleLayer, "\0") || preg_match('//u', $rustleLayer) !== 1))) {
      throw new InvalidArgumentException('A sprite thicket rustle names its world layer id.');
    }
  }

  /** The same thicket hiding at most a sprite of this height, all of it at most. */
  public function fitHeight(int $height): self
  {
    return $this->depth <= $height ? $this : new self(max(1, $height), $this->opacity, $this->rustleLayer);
  }

  /** @return array{depth: int, opacity: float, rustle?: array{layer: string}} */
  public function toArray(): array
  {
    return ['depth' => $this->depth, 'opacity' => $this->opacity,
      ...($this->rustleLayer === null ? [] : ['rustle' => ['layer' => $this->rustleLayer]])];
  }
}
