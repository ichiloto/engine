<?php

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;
use RuntimeException;

/**
 * Checks a character sheet against its image before it can replace the
 * terminal glyph. A missing, unreadable or wrongly shaped sheet keeps the
 * glyph and is reported once per distinct failure.
 */
final class CharacterSheetAssetGuard
{
  private ?string $lastFailure = null;

  public function __construct(private readonly string $assetRoot, private readonly string $context) {}

  /** @return array{width: int, height: int}|null The frame size, or null when the sheet cannot be drawn. */
  public function getFrameSize(CharacterSheet $sheet): ?array
  {
    try {
      $image = PngAssetPreflight::inspect($this->assetRoot, $sheet->asset);
      $frame = $sheet->getFrameSize($image['width'], $image['height']);
      $this->lastFailure = null;
      return $frame;
    } catch (RuntimeException|InvalidArgumentException $error) {
      $failure = $error->getMessage();
      if ($failure !== $this->lastFailure) {
        Debug::warn(sprintf('%s sprites2d unavailable; keeping terminal sprite: %s', $this->context, $failure));
        $this->lastFailure = $failure;
      }
      return null;
    }
  }
}
