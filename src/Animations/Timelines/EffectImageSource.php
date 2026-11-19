<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/** Authored sheet identity; current PNG facts supply its pixel rectangle. */
final readonly class EffectImageSource
{
  public function __construct(public string $asset, public int $columns = 1, public int $rows = 1,
    public int $frame = 0)
  {
    SpriteValidation::validateAssetPath($asset);
    if ($columns < 1 || $rows < 1 || $frame < 0 || $frame >= $columns * $rows) {
      throw new InvalidArgumentException('Effect image source frame is outside its authored sheet grid.');
    }
  }

  public static function fromCommand(array $command): self
  {
    $data = $command['payload'] ?? [];
    return new self($command['assetId'], $data['columns'] ?? 1, $data['rows'] ?? 1, $data['sourceFrame'] ?? 0);
  }

  public function getSourceRect(string $assetRoot): SpriteSourceRect
  {
    $size = PngAssetPreflight::getAvailableSize($assetRoot, $this->asset);
    if ($size === null || $size['width'] % $this->columns !== 0 || $size['height'] % $this->rows !== 0) {
      throw new InvalidArgumentException('Effect image is unavailable or no longer matches its authored sheet: ' . $this->asset);
    }
    $width = intdiv($size['width'], $this->columns);
    $height = intdiv($size['height'], $this->rows);
    return new SpriteSourceRect(($this->frame % $this->columns) * $width,
      intdiv($this->frame, $this->columns) * $height, $width, $height);
  }

  /** Descriptor identity only; artwork replacement never changes an authored identity. */
  public function getKey(): string
  {
    return json_encode([$this->asset, $this->columns, $this->rows, $this->frame], JSON_THROW_ON_ERROR);
  }
}
