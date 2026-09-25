<?php

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Util\Debug;
use RuntimeException;

/** Preflight the complete set before any direction can mask its terminal fallback. */
final class DirectionalGraphicalSpriteAssetGuard
{
  private ?string $lastFailure = null;

  public function __construct(private readonly string $assetRoot, private readonly string $context) {}

  public function isAvailable(DirectionalGraphicalSpriteSet $sprites): bool
  {
    try {
      foreach ([$sprites->north, $sprites->east, $sprites->south, $sprites->west] as $definition) {
        $sheet = $definition->sheet;
        $crop = $sheet === null ? $definition->sourceRect : new SpriteSourceRect(
          0, 0, $sheet->columns * $sheet->frameWidth, $sheet->rows * $sheet->frameHeight,
        );
        PngAssetPreflight::inspect($this->assetRoot, $definition->asset, $crop);
      }
      $this->lastFailure = null;
      return true;
    } catch (RuntimeException $error) {
      $failure = $error->getMessage();
      if ($failure !== $this->lastFailure) {
        Debug::warn(sprintf('%s sprites2d unavailable; keeping terminal sprite: %s', $this->context, $failure));
        $this->lastFailure = $failure;
      }
      return false;
    }
  }
}
