<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Text;

use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/** A replaceable project asset and explicit face, not decoded font identity. */
final readonly class FontReference
{
  public function __construct(public string $asset, public int $faceIndex = 0)
  {
    SpriteValidation::validateAssetPath($asset);
    if (strlen($asset) > 4096 || $faceIndex < 0 || $faceIndex > 31) {
      throw new InvalidArgumentException('Font references require paths of at most 4096 bytes and faceIndex in 0..31.');
    }
  }

  /** @return array{asset: string, faceIndex: int} */
  public function toArray(): array
  {
    return ['asset' => $this->asset, 'faceIndex' => $this->faceIndex];
  }
}
